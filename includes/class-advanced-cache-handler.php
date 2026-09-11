<?php
/**
 * Advanced_Cache_Handler class for the PerformanceOptimise plugin.
 *
 * Handles the creation and removal of an advanced-cache.php file used for serving cached content.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Advanced_Cache_Handler' ) ) {
	/**
	 * Class Advanced_Cache_Handler
	 *
	 * Manages the creation and removal of the advanced-cache.php file.
	 *
	 * @since 1.0.0
	 */
	class Advanced_Cache_Handler {

		/**
		 * Marker inside advanced-cache.php drop-in so we do not overwrite or delete other plugins' files.
		 *
		 * @var string
		 */
		public const DROPIN_MARKER = 'WPPO_ADVANCED_CACHE_DROPIN';

		/**
		 * Path to the advanced-cache.php drop-in.
		 *
		 * @return string
		 */
		public static function get_dropin_path(): string {
			return wp_normalize_path( WP_CONTENT_DIR . '/advanced-cache.php' );
		}

		/**
		 * Log a drop-in inspection problem at most once per hour.
		 *
		 * This runs from is_our_dropin()'s error branches on hot per-request
		 * paths (admin notices, System Info); a persistently broken filesystem
		 * must not insert an activity row on every page load, so logging is
		 * transient-rate-limited (audit #888 Part 2 review).
		 *
		 * @since NEXT
		 * @param string $message Message to log.
		 * @return void
		 */
		private static function log_dropin_issue( string $message ): void {
			$rate_key = Util::transient_key( 'wppo_dropin_issue_logged' );
			if ( get_transient( $rate_key ) ) {
				return;
			}
			set_transient( $rate_key, 1, HOUR_IN_SECONDS );
			Log::add( $message );
		}

		/**
		 * Whether the existing advanced-cache.php (if any) was created by this plugin.
		 *
		 * Error semantics (audit #888 finding 23): on WP_Filesystem init
		 * failure, missing methods, or read errors the method returns `false`
		 * and logs via Log::add (rate-limited to once per hour) — the swallowed
		 * failure is no longer silent. `false` is conservative in every caller:
		 * create()/remove() only act on a positively identified WPPO drop-in
		 * (a foreign or unknown file is left untouched, never overwritten or
		 * deleted), so an unknown status can only skip work, never damage
		 * another plugin's drop-in.
		 *
		 * @since 1.0.0
		 * @since NEXT Filesystem errors are logged instead of silently swallowed.
		 * @return bool True when the drop-in exists and is WPPO-owned; false otherwise (including unknown).
		 */
		public static function is_our_dropin(): bool {
			global $wp_filesystem;

			$handler_file = self::get_dropin_path();

			if ( ! $wp_filesystem && ! Util::init_filesystem() ) {
				self::log_dropin_issue( __( 'Could not inspect advanced-cache.php: WP_Filesystem unavailable.', 'performance-optimisation' ) );
				return false;
			}

			if ( ! is_object( $wp_filesystem ) || ! method_exists( $wp_filesystem, 'exists' ) || ! method_exists( $wp_filesystem, 'get_contents' ) ) {
				self::log_dropin_issue( __( 'Could not inspect advanced-cache.php: filesystem API incomplete.', 'performance-optimisation' ) );
				return false;
			}

			try {
				if ( ! $wp_filesystem->exists( $handler_file ) ) {
					return false;
				}

				$contents = $wp_filesystem->get_contents( $handler_file );
			} catch ( \Throwable $e ) {
				self::log_dropin_issue(
					sprintf(
						/* translators: %s: error message */
						__( 'Could not read advanced-cache.php: %s', 'performance-optimisation' ),
						sanitize_text_field( $e->getMessage() )
					)
				);
				return false;
			}

			if ( ! is_string( $contents ) ) {
				self::log_dropin_issue( __( 'Could not read advanced-cache.php: filesystem returned no content.', 'performance-optimisation' ) );
				return false;
			}

			if ( false !== strpos( $contents, self::DROPIN_MARKER ) ) {
				return true;
			}

			// Legacy drop-ins from releases before DROPIN_MARKER was added.
			return false !== strpos( $contents, 'is_user_logged_in_without_wp' );
		}

		/**
		 * Another plugin (or host) owns advanced-cache.php; we must not replace it.
		 *
		 * @return bool
		 */
		public static function foreign_dropin_present(): bool {
			global $wp_filesystem;

			$handler_file = self::get_dropin_path();

			if ( ! $wp_filesystem && ! Util::init_filesystem() ) {
				return false;
			}

			if ( ! is_object( $wp_filesystem ) || ! method_exists( $wp_filesystem, 'exists' ) ) {
				return false;
			}

			try {
				if ( ! $wp_filesystem->exists( $handler_file ) ) {
					return false;
				}
			} catch ( \Throwable $e ) {
				return false;
			}

			return ! self::is_our_dropin();
		}

		/**
		 * Path to the backup copy of our drop-in kept alongside advanced-cache.php.
		 *
		 * The backup lives in the same directory so tmp, final, and backup are
		 * always on the same filesystem and the rename stays atomic.
		 *
		 * @since NEXT
		 * @return string Backup file path.
		 */
		public static function get_dropin_backup_path(): string {
			return self::get_dropin_path() . '.wppo-backup';
		}

		/**
		 * Atomically write the drop-in via tmp-plus-rename with backup and verification.
		 *
		 * Writes the generated code to a uniquely named temp sibling, verifies the
		 * marker and PHP open tag, re-checks drop-in ownership immediately before
		 * the rename, keeps one backup copy of the previous working file, renames
		 * atomically, then verifies the final file. On any failure the old file
		 * (or no file) survives and no half-written file is left in place, so the
		 * site fails open with caching bypassed.
		 *
		 * @since NEXT
		 * @param string $handler_code Generated drop-in code.
		 * @return bool True on success (or when a foreign drop-in is left untouched), false on failure.
		 */
		private static function atomic_write_dropin( string $handler_code ): bool {
			global $wp_filesystem;

			if ( false === strpos( $handler_code, self::DROPIN_MARKER ) ) {
				self::log_dropin_issue( __( 'Could not write advanced-cache.php: generated code is missing the drop-in marker.', 'performance-optimisation' ) );
				return false;
			}

			if ( 0 !== strpos( ltrim( $handler_code ), '<?php' ) ) {
				self::log_dropin_issue( __( 'Could not write advanced-cache.php: generated code is missing the PHP open tag.', 'performance-optimisation' ) );
				return false;
			}

			if ( ! is_object( $wp_filesystem ) ) {
				self::log_dropin_issue( __( 'Could not write advanced-cache.php: WP_Filesystem unavailable.', 'performance-optimisation' ) );
				return false;
			}

			if ( ! method_exists( $wp_filesystem, 'put_contents' ) || ! method_exists( $wp_filesystem, 'get_contents' ) || ! method_exists( $wp_filesystem, 'exists' ) ) {
				self::log_dropin_issue( __( 'Could not write advanced-cache.php: filesystem API incomplete.', 'performance-optimisation' ) );
				return false;
			}

			$handler_file = self::get_dropin_path();
			$backup_file  = self::get_dropin_backup_path();
			$chmod_file   = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
			$tmp_file     = '';

			try {
				// Unique tmp suffix: wide rand range plus pid/uniqid so two
				// concurrent create() calls cannot pick the same tmp name.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Fallback only when wp_rand() is unavailable (WP < 6.2 compat); uniqueness is all that is needed for the tmp suffix.
				$rand_suffix = mt_rand( 1000000, 9999999 );
				if ( function_exists( 'wp_rand' ) ) {
					try {
						$rand_suffix = wp_rand( 1000000, 9999999 );
					} catch ( \Throwable $ignored_rand ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Keep the mt_rand() fallback when the wp_rand() stub is unavailable.
					}
				}
				$pid_part  = function_exists( 'getmypid' ) ? (int) getmypid() : 0;
				$uniq_part = substr( md5( uniqid( (string) microtime( true ), true ) ), 0, 8 );
				$tmp_file  = $handler_file . '.tmp.' . $rand_suffix . '-' . $pid_part . '-' . $uniq_part;

				$written = $wp_filesystem->put_contents( $tmp_file, $handler_code, $chmod_file );
				if ( ! $written ) {
					if ( method_exists( $wp_filesystem, 'delete' ) ) {
						$wp_filesystem->delete( $tmp_file );
					}
					self::log_dropin_issue( __( 'Could not write advanced-cache.php: temporary file write failed, previous file kept.', 'performance-optimisation' ) );
					return false;
				}

				$tmp_contents = $wp_filesystem->get_contents( $tmp_file );
				if ( ! is_string( $tmp_contents ) || false === strpos( $tmp_contents, self::DROPIN_MARKER ) || 0 !== strpos( ltrim( $tmp_contents ), '<?php' ) ) {
					if ( method_exists( $wp_filesystem, 'delete' ) ) {
						$wp_filesystem->delete( $tmp_file );
					}
					self::log_dropin_issue( __( 'Could not write advanced-cache.php: temporary file verification failed, previous file kept.', 'performance-optimisation' ) );
					return false;
				}

				// Ownership may have changed while we wrote tmp; never overwrite a foreign drop-in.
				if ( $wp_filesystem->exists( $handler_file ) && ! self::is_our_dropin() ) {
					if ( method_exists( $wp_filesystem, 'delete' ) ) {
						$wp_filesystem->delete( $tmp_file );
					}
					self::log_dropin_issue( __( 'Skipped writing advanced-cache.php: a foreign drop-in appeared during the write.', 'performance-optimisation' ) );
					return true;
				}

				// Best-effort backup of the previous working file; a failed backup must not block the rename.
				if ( $wp_filesystem->exists( $handler_file ) && self::is_our_dropin() && method_exists( $wp_filesystem, 'copy' ) ) {
					$backed_up = $wp_filesystem->copy( $handler_file, $backup_file, true, $chmod_file );
					if ( ! $backed_up ) {
						self::log_dropin_issue( __( 'Could not back up advanced-cache.php before replacing it.', 'performance-optimisation' ) );
					}
				}

				$moved = false;
				if ( method_exists( $wp_filesystem, 'move' ) ) {
					$moved = (bool) $wp_filesystem->move( $tmp_file, $handler_file, true );
				}

				if ( ! $moved ) {
					if ( method_exists( $wp_filesystem, 'copy' ) ) {
						$copied = (bool) $wp_filesystem->copy( $tmp_file, $handler_file, true, $chmod_file );
						if ( $copied ) {
							if ( method_exists( $wp_filesystem, 'delete' ) ) {
								$wp_filesystem->delete( $tmp_file );
							}
							$moved = true;
						}
					}

					if ( ! $moved ) {
						if ( method_exists( $wp_filesystem, 'delete' ) ) {
							$wp_filesystem->delete( $tmp_file );
						}
						self::log_dropin_issue( __( 'Could not write advanced-cache.php: rename failed, previous file kept.', 'performance-optimisation' ) );
						return false;
					}
				}

				$final_contents = $wp_filesystem->get_contents( $handler_file );
				if ( ! is_string( $final_contents ) || false === strpos( $final_contents, self::DROPIN_MARKER ) || 0 !== strpos( ltrim( $final_contents ), '<?php' ) ) {
					$restored = false;
					if ( method_exists( $wp_filesystem, 'copy' ) && method_exists( $wp_filesystem, 'exists' ) && $wp_filesystem->exists( $backup_file ) ) {
						$restored = (bool) $wp_filesystem->copy( $backup_file, $handler_file, true, $chmod_file );
					}
					if ( ! $restored && method_exists( $wp_filesystem, 'delete' ) ) {
						$wp_filesystem->delete( $handler_file );
					}
					self::log_dropin_issue( __( 'Could not verify advanced-cache.php after write; previous backup restored where available.', 'performance-optimisation' ) );
					return false;
				}

				return true;
			} catch ( \Throwable $e ) {
				if ( '' !== $tmp_file && is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'delete' ) ) {
					try {
						$wp_filesystem->delete( $tmp_file );
					} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort tmp cleanup must never throw.
					}
				}
				self::log_dropin_issue(
					sprintf(
						/* translators: %s: error message */
						__( 'Could not write advanced-cache.php: %s', 'performance-optimisation' ),
						sanitize_text_field( $e->getMessage() )
					)
				);
				return false;
			}
		}

		/**
		 * Creates the advanced-cache.php file.
		 *
		 * Generates the file to serve cached content, including gzip versions, and ensures required directories exist.
		 * The generated drop-in honours the per-page `.wppo-no-cache` marker that the
		 * Cache class writes for DONOTCACHEPAGE pages. This is a best-effort serve-time
		 * check: a page that is already cached when a plugin first sets the constant is
		 * served from the stale file before WordPress boots (so the marker is never
		 * written) until a cache clear, post invalidation, or the one-time version
		 * upgrade purge removes it.
		 *
		 * @return bool True when the drop-in is left in a correct state (regenerated,
		 *              already ours, or a foreign drop-in left untouched), false on
		 *              filesystem failure.
		 * @since 1.0.0
		 */
		public static function create(): bool {

			global $wp_filesystem;

			if ( self::foreign_dropin_present() ) {
				// Another plugin owns the drop-in; leave it alone. Not a failure.
				return true;
			}

			if ( ! $wp_filesystem && ! Util::init_filesystem() ) {
				return false;
			}

			$site_url = home_url();
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export produces a correctly escaped single-quoted PHP literal for the generated drop-in.
			$site_url_escaped = var_export( $site_url, true );
			// COOKIEHASH fallback must be scheme-agnostic; the logged-in cookie name does not include
			// scheme/path, so md5(home_url()) would mismatch on http/https or subdirectory installs.
			// Derive the fallback from the host only.
			// @since NEXT Fallback now uses host-only hash to avoid scheme mismatch.
			$site_host     = wp_parse_url( $site_url, PHP_URL_HOST );
			$fallback_hash = $site_host ? md5( $site_host ) : md5( $site_url );
			$cookie_hash   = defined( 'COOKIEHASH' ) ? COOKIEHASH : $fallback_hash;

			// Canonical host pinned into the drop-in (Host-header cache-poisoning
			// guard, @since NEXT): the pre-boot serve path below only ever reads
			// cache/wppo/<canonical-host>/, so a forged Host header is served
			// uncached (falls through to WordPress) and can never create or
			// serve a poisoned file. Empty canonical fails open to uncached.
			//
			// Known tradeoff: a single canonical host is baked at create() time
			// because the pre-boot drop-in has no blog context. On multisite or
			// domain-mapped networks with several valid hosts, non-primary hosts
			// fail open (served uncached) rather than served from their own tree.
			// Per-Host trees were inherently multisite-safe but also inherently
			// poisonable, so fail-open is the safe direction; a future
			// enhancement could bake an allowlist of network hosts instead of
			// strict equality.
			$canonical_host = Util::get_canonical_host();
			if ( '' === $canonical_host && is_string( $site_host ) && '' !== $site_host ) {
				$canonical_host = Util::normalize_cache_host( $site_host );
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export produces a correctly escaped single-quoted PHP literal for the generated drop-in.
			$canonical_host_escaped = var_export( $canonical_host, true );

			// Cache life in hours baked into the drop-in; 0 = never expire.
			// NOTE: create() runs in plugin context so Util::get_settings() is fine
			// here, but the generated drop-in string below serves cached pages
			// before WordPress (and Util) loads — it must stay Util-free.
			// See tests/php/SettingsReadGuardTest.php.
			$wppo_options = Util::get_settings();
			$cache_life   = isset( $wppo_options['cache_settings']['cacheLife'] ) ? absint( $wppo_options['cache_settings']['cacheLife'] ) : 0;

			// Woo safe-mode policy (issue #922), baked in at generation time.
			// The generated drop-in serves cached pages before WordPress boots,
			// so it cannot read settings or run the wppo_woo_cacheable filter at
			// serve time; the toggle is resolved here and written into the file.
			// Semantics mirror Cache::is_woo_excluded(): an absent key defaults to
			// enabled (fail-safe), and malformed values normalize to enabled.
			$woo_safe_mode = true;
			if ( method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_safe_mode_enabled' ) ) {
				try {
					$woo_safe_mode = \PerformanceOptimise\Inc\Util::is_woo_safe_mode_enabled( is_array( $wppo_options ) ? $wppo_options : null );
				} catch ( \Throwable $e ) {
					unset( $e );
					$woo_safe_mode = true;
				}
			} elseif ( isset( $wppo_options['cache_settings']['wooSafeMode'] ) ) {
				$parsed_mode   = filter_var( $wppo_options['cache_settings']['wooSafeMode'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				$woo_safe_mode = null === $parsed_mode ? true : $parsed_mode;
			}

			// Pre-boot request-URI segments to exclude. The defaults are always
			// baked (pre-#922 behaviour); under safe mode the configured
			// WooCommerce page slugs are resolved so a custom slug (e.g. /basket/)
			// never reaches wppo_serve_cache_file().
			$woo_uri_segments = array( 'cart', 'checkout', 'my-account' );
			if ( $woo_safe_mode ) {
				foreach ( Util::get_woo_excluded_paths() as $woo_path ) {
					$woo_path = strtolower( trim( (string) $woo_path, '/' ) );
					$woo_path = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $woo_path );
					$woo_path = trim( $woo_path, '/' );
					if ( '' !== $woo_path && ! in_array( $woo_path, $woo_uri_segments, true ) ) {
						$woo_uri_segments[] = $woo_path;
					}
				}
			}
			$woo_uri_pattern = implode( '|', array_map( 'preg_quote', $woo_uri_segments ) );

			$handler_code = '<?php' . PHP_EOL .
			'// ' . self::DROPIN_MARKER . PHP_EOL .
			'if ( ! defined( \'ABSPATH\' ) ) {' . PHP_EOL .
			'	exit;' . PHP_EOL .
			'}' . PHP_EOL . PHP_EOL .

			'$site_url       = ' . $site_url_escaped . ';' . PHP_EOL .
			'$canonical_host = ' . $canonical_host_escaped . ';' . PHP_EOL .
			'$raw_domain    = isset( $_SERVER[\'HTTP_HOST\'] ) ? (string) $_SERVER[\'HTTP_HOST\'] : \'\';' . PHP_EOL .
			'$request_base   = $raw_domain;' . PHP_EOL .
			'if ( isset( $request_base[0] ) && \'[\' === $request_base[0] ) { $bracket_end = strpos( $request_base, \']\' ); if ( false === $bracket_end ) { $request_base = \'\'; } else { $request_rest = substr( $request_base, $bracket_end + 1 ); if ( \'\' !== $request_rest && \':\' !== $request_rest[0] ) { $request_base = \'\'; } else { $request_base = substr( $request_base, 1, $bracket_end - 1 ); } } } elseif ( substr_count( $request_base, \':\' ) <= 1 ) { $request_base = explode( \':\', $request_base, 2 )[0]; }' . PHP_EOL .
			'$idn_host       = function_exists( \'idn_to_ascii\' ) ? @idn_to_ascii( $request_base, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 ) : false;' . PHP_EOL .
			'$request_base   = ( is_string( $idn_host ) && \'\' !== $idn_host ) ? $idn_host : $request_base;' . PHP_EOL .
			'$site_domain   = strtolower( preg_replace( \'/[^a-z0-9.:-]+/i\', \'\', $raw_domain ) );' . PHP_EOL .
			'$request_host  = strtolower( preg_replace( \'/[^a-z0-9.:-]+/i\', \'\', $request_base ) );' . PHP_EOL .
			'$request_uri   = isset( $_SERVER[\'REQUEST_URI\'] ) ? (string) parse_url( $_SERVER[\'REQUEST_URI\'], PHP_URL_PATH ) : \'\';' . PHP_EOL .
			'$request_uri   = rawurldecode( $request_uri );' . PHP_EOL .
			'$request_uri   = function_exists( \'wp_normalize_path\' ) ? wp_normalize_path( $request_uri ) : str_replace( \'\\\\\', \'/\', $request_uri );' . PHP_EOL .
			'$cache_life    = ' . $cache_life . ';' . PHP_EOL . PHP_EOL .

			'if ( \'\' === $site_domain || \'\' === $canonical_host || \'\' === $request_host || $request_host !== $canonical_host || strpos( $site_domain, \'..\' ) !== false || strpos( $request_uri, \'..\' ) !== false || strpos( $request_uri, "\0" ) !== false || strpos( $site_domain, "\0" ) !== false || false !== strpos( $request_uri, \'\\\\\' ) || (bool) preg_match( \'#^[a-zA-Z]:#\', ltrim( $request_uri ) ) ) {' . PHP_EOL .
			'	return;' . PHP_EOL .
			'}' . PHP_EOL . PHP_EOL .

			'if ( isset( $_COOKIE[\'woocommerce_items_in_cart\'] ) || isset( $_COOKIE[\'woocommerce_cart_hash\'] ) ) {' . PHP_EOL .
			'	return;' . PHP_EOL .
			'}' . PHP_EOL .
			( $woo_safe_mode
				? 'foreach ( (array) $_COOKIE as $k => $v ) { if ( 0 === strpos( $k, \'wp_woocommerce_session_\' ) && ! empty( $v ) ) { return; } }' . PHP_EOL . PHP_EOL . PHP_EOL
				: PHP_EOL // Safe mode disabled: restore pre-#922 drop-in behaviour.
			) .

			'// WooCommerce AJAX endpoints are dynamic JSON and must never be served from the static cache (issue #907).' . PHP_EOL .
			'// The segment match mirrors Cache::is_wc_ajax_request() case-insensitively, including the raw' . PHP_EOL .
			'// QUERY_STRING fallback (intentional pre-boot duplication — the drop-in serves cached pages before' . PHP_EOL .
			'// WordPress boots, so no sanitize_text_field/Util calls here); the empty-QUERY_STRING gate before' . PHP_EOL .
			'// wppo_serve_cache_file() below remains as a second backstop.' . PHP_EOL .
			'// Woo safe-mode (issue #922): when enabled, the session-cookie and add-to-cart guards are' . PHP_EOL .
			'// baked in above/below, and the configured WooCommerce page paths are added to the URI' . PHP_EOL .
			'// guard. When disabled, only the pre-#922 guards remain (parity with master).' . PHP_EOL .
			'if ( preg_match( \'#(^|/)wc-ajax(/|$)#i\', $request_uri ) || isset( $_GET[\'wc-ajax\'] )' . ( $woo_safe_mode ? ' || isset( $_GET[\'add-to-cart\'] )' : '' ) . ' ) {' . PHP_EOL .
			'	return;' . PHP_EOL .
			'}' . PHP_EOL .
			'if ( ! empty( $_SERVER[\'QUERY_STRING\'] ) && preg_match( \'/(?:^|&)wc-ajax(?:=|&|$)/i\', $_SERVER[\'QUERY_STRING\'] ) ) {' . PHP_EOL .
			'	return;' . PHP_EOL .
			'}' . PHP_EOL .
			( $woo_safe_mode
				? 'if ( ! empty( $_SERVER[\'QUERY_STRING\'] ) && preg_match( \'/(?:^|&)add-to-cart(?:=|&|$)/i\', $_SERVER[\'QUERY_STRING\'] ) ) {' . PHP_EOL .
				'	return;' . PHP_EOL .
				'}' . PHP_EOL . PHP_EOL
				: PHP_EOL // Keeps the blank-line separator consistent below.
			) .

			'// WooCommerce Store API routes are dynamic JSON and must never be served from the static cache (issue #962).' . PHP_EOL .
			'// Unconditional on safe mode, mirroring wc-ajax: wc/store, wcstore, wp-json/wc/store, wp-json/wcstore,' . PHP_EOL .
			'// plus the plain-permalink ?rest_route=/wc/store/... form (path is "/" there, so the' . PHP_EOL .
			'// path-only regex would miss it — mirrors the QUERY_STRING guard above).' . PHP_EOL .
			'if ( preg_match( \'#(^|/)(?:wc/store|wcstore|wp-json/wc/store|wp-json/wcstore)(/|$)#i\', $request_uri ) ) {' . PHP_EOL .
			'	return;' . PHP_EOL .
			'}' . PHP_EOL .
			'if ( isset( $_GET[\'rest_route\'] ) && is_string( $_GET[\'rest_route\'] ) && preg_match( \'#(^|/)(?:wc/store|wcstore|wp-json/wc/store|wp-json/wcstore)(/|$)#i\', \'/\' . ltrim( $_GET[\'rest_route\'], \'/\' ) ) ) {' . PHP_EOL .
			'	return;' . PHP_EOL .
			'}' . PHP_EOL .
			'if ( ! empty( $_SERVER[\'QUERY_STRING\'] ) && preg_match( \'#rest_route=[^&]*(?:wc/store|wcstore)#i\', rawurldecode( $_SERVER[\'QUERY_STRING\'] ) ) ) {' . PHP_EOL .
			'	return;' . PHP_EOL .
			'}' . PHP_EOL . PHP_EOL .

			'if ( preg_match( \'#^/(?:' . $woo_uri_pattern . ')(?:/|$)#i\', $request_uri ) || preg_match( \'/(?:sitemap[^\/]*\.xml|wp-sitemap[^\/]*\.xml|\.xml)$/i\', $request_uri ) ) {' . PHP_EOL .
			'	return;' . PHP_EOL .
			'}' . PHP_EOL . PHP_EOL .

			'if ( \'/\' !== substr( $request_uri, -1 ) ) {' . PHP_EOL .
			'	$request_uri .= \'/\';' . PHP_EOL .
			'}' . PHP_EOL . PHP_EOL .

			'$file_path      = WP_CONTENT_DIR . \'/cache/wppo/\' . $canonical_host . $request_uri . \'index.html\';' . PHP_EOL .
			'$file_path      = str_replace( \'\\\\\', \'/\', $file_path );' . PHP_EOL .
			'$file_path      = preg_replace( \'#/+#\', \'/\', $file_path );' . PHP_EOL .
			'$file_path      = rtrim( $file_path, \'/\' );' . PHP_EOL .
			'$cache_base     = str_replace( \'\\\\\', \'/\', WP_CONTENT_DIR . \'/cache/wppo/\' . $canonical_host . \'/\' );' . PHP_EOL .
			'if ( \'\' === $canonical_host || 0 !== strpos( $file_path, $cache_base ) ) {' . PHP_EOL .
			'	return;' . PHP_EOL .
			'}' . PHP_EOL .
			'$gzip_file_path = $file_path . \'.gz\';' . PHP_EOL .
			'$brotli_file_path = $file_path . \'.br\';' . PHP_EOL .
			'$accept_encoding = isset( $_SERVER[\'HTTP_ACCEPT_ENCODING\'] ) ? (string) $_SERVER[\'HTTP_ACCEPT_ENCODING\'] : \'\';' . PHP_EOL . PHP_EOL .

			'$no_cache_marker = dirname( $file_path ) . \'/.wppo-no-cache\';' . PHP_EOL .
			'if ( file_exists( $no_cache_marker ) ) {' . PHP_EOL .
			'	return;' . PHP_EOL .
			'}' . PHP_EOL . PHP_EOL .

			'function is_user_logged_in_without_wp( $site_url ) {' . PHP_EOL .
			'	$logged_in_cookie = \'wordpress_logged_in_\' . \'' . $cookie_hash . '\';' . PHP_EOL .
			'	$cookie_prefix = \'wp-wpml_\';' . PHP_EOL .
			'	if ( isset( $_COOKIE[ $logged_in_cookie ] ) ) {' . PHP_EOL .
			'		return true;' . PHP_EOL .
			'	}' . PHP_EOL .
			'	foreach ( $_COOKIE as $name => $value ) {' . PHP_EOL .
			'		if ( strpos( $name, \'wordpress_logged_in_\' ) === 0 || strpos( $name, \'wp-rs-\' ) === 0 ) {' . PHP_EOL .
			'			return true;' . PHP_EOL .
			'		}' . PHP_EOL .
			'	}' . PHP_EOL .
			'	return false;' . PHP_EOL .
			'}' . PHP_EOL . PHP_EOL .

			'function wppo_serve_cache_file( $file_path, $gzip_file_path, $brotli_file_path, $cache_life, $accept_encoding ) {' . PHP_EOL .
			'	if ( $cache_life > 0 ) {' . PHP_EOL .
			'		$check_path = $file_path;' . PHP_EOL .
			'		if ( false !== strpos( $accept_encoding, \'br\' ) && file_exists( $brotli_file_path ) ) {' . PHP_EOL .
			'			$check_path = $brotli_file_path;' . PHP_EOL .
			'		} elseif ( file_exists( $gzip_file_path ) ) {' . PHP_EOL .
			'			$check_path = $gzip_file_path;' . PHP_EOL .
			'		}' . PHP_EOL .
			'		if ( file_exists( $check_path ) && ( time() - (int) filemtime( $check_path ) ) > $cache_life * 3600 ) {' . PHP_EOL .
			'			return;' . PHP_EOL .
			'		}' . PHP_EOL .
			'	}' . PHP_EOL .
			'	if ( false !== strpos( $accept_encoding, \'br\' ) && file_exists( $brotli_file_path ) ) {' . PHP_EOL .
			'		$last_modified_time = filemtime( $brotli_file_path );' . PHP_EOL .
			'		$etag               = md5_file( $brotli_file_path );' . PHP_EOL .
			'		header( \'Last-Modified: \' . gmdate( \'D, d M Y H:i:s\', $last_modified_time ) . \' GMT\' );' . PHP_EOL .
			'		header( \'ETag: "\' . $etag . \'"\' );' . PHP_EOL .
			'		header( \'Content-Type: text/html\' );' . PHP_EOL .
			'		header( \'Content-Encoding: br\' );' . PHP_EOL .
			'		header( \'Vary: Accept-Encoding\' );' . PHP_EOL .
			'		header( \'X-Content-Type-Options: nosniff\' );' . PHP_EOL .
			'		header( \'X-Frame-Options: SAMEORIGIN\' );' . PHP_EOL .
			'		header( \'Referrer-Policy: strict-origin-when-cross-origin\' );' . PHP_EOL . PHP_EOL .
			'		if ( ( isset( $_SERVER[\'HTTP_IF_MODIFIED_SINCE\'] ) && strtotime( $_SERVER[\'HTTP_IF_MODIFIED_SINCE\'] ) >= $last_modified_time ) ||' . PHP_EOL .
			'		( isset( $_SERVER[\'HTTP_IF_NONE_MATCH\'] ) && trim( $_SERVER[\'HTTP_IF_NONE_MATCH\'] ) === $etag ) ) {' . PHP_EOL .
			'			header( \'HTTP/1.1 304 Not Modified\' );' . PHP_EOL .
			'			header( \'Connection: close\' );' . PHP_EOL .
			'			exit;' . PHP_EOL .
			'		}' . PHP_EOL . PHP_EOL .
			'		readfile( $brotli_file_path );' . PHP_EOL .
			'		exit;' . PHP_EOL .
			'	} elseif ( file_exists( $gzip_file_path ) ) {' . PHP_EOL .
			'		$last_modified_time = filemtime( $gzip_file_path );' . PHP_EOL .
			'		$etag               = md5_file( $gzip_file_path );' . PHP_EOL .
			'		header( \'Last-Modified: \' . gmdate( \'D, d M Y H:i:s\', $last_modified_time ) . \' GMT\' );' . PHP_EOL .
			'		header( \'ETag: "\' . $etag . \'"\' );' . PHP_EOL .
			'		header( \'Content-Type: text/html\' );' . PHP_EOL .
			'		header( \'Content-Encoding: gzip\' );' . PHP_EOL .
			'		header( \'X-Content-Type-Options: nosniff\' );' . PHP_EOL .
			'		header( \'X-Frame-Options: SAMEORIGIN\' );' . PHP_EOL .
			'		header( \'Referrer-Policy: strict-origin-when-cross-origin\' );' . PHP_EOL . PHP_EOL .
			'		if ( ( isset( $_SERVER[\'HTTP_IF_MODIFIED_SINCE\'] ) && strtotime( $_SERVER[\'HTTP_IF_MODIFIED_SINCE\'] ) >= $last_modified_time ) ||' . PHP_EOL .
			'		( isset( $_SERVER[\'HTTP_IF_NONE_MATCH\'] ) && trim( $_SERVER[\'HTTP_IF_NONE_MATCH\'] ) === $etag ) ) {' . PHP_EOL .
			'			header( \'HTTP/1.1 304 Not Modified\' );' . PHP_EOL .
			'			header( \'Connection: close\' );' . PHP_EOL .
			'			exit;' . PHP_EOL .
			'		}' . PHP_EOL . PHP_EOL .
			'		readfile( $gzip_file_path );' . PHP_EOL .
			'		exit;' . PHP_EOL .
			'	} elseif ( file_exists( $file_path ) ) {' . PHP_EOL .
			'		$last_modified_time = filemtime( $file_path );' . PHP_EOL .
			'		$etag               = md5_file( $file_path );' . PHP_EOL .
			'		header( \'Last-Modified: \' . gmdate( \'D, d M Y H:i:s\', $last_modified_time ) . \' GMT\' );' . PHP_EOL .
			'		header( \'ETag: "\' . $etag . \'"\' );' . PHP_EOL .
			'		header( \'Content-Type: text/html\' );' . PHP_EOL .
			'		header( \'X-Content-Type-Options: nosniff\' );' . PHP_EOL .
			'		header( \'X-Frame-Options: SAMEORIGIN\' );' . PHP_EOL .
			'		header( \'Referrer-Policy: strict-origin-when-cross-origin\' );' . PHP_EOL . PHP_EOL .
			'		if ( ( isset( $_SERVER[\'HTTP_IF_MODIFIED_SINCE\'] ) && strtotime( $_SERVER[\'HTTP_IF_MODIFIED_SINCE\'] ) >= $last_modified_time ) ||' . PHP_EOL .
			'		( isset( $_SERVER[\'HTTP_IF_NONE_MATCH\'] ) && trim( $_SERVER[\'HTTP_IF_NONE_MATCH\'] ) === $etag ) ) {' . PHP_EOL .
			'			header( \'HTTP/1.1 304 Not Modified\' );' . PHP_EOL .
			'			header( \'Connection: close\' );' . PHP_EOL .
			'			exit;' . PHP_EOL .
			'		}' . PHP_EOL . PHP_EOL .
			'		readfile( $file_path );' . PHP_EOL .
			'		exit;' . PHP_EOL .
			'	}' . PHP_EOL .
			'}' . PHP_EOL . PHP_EOL .
			'$is_logged_in = is_user_logged_in_without_wp( $site_url );' . PHP_EOL .
			'$has_query    = ! empty( $_SERVER[\'QUERY_STRING\'] );' . PHP_EOL . PHP_EOL .
			'if ( ! $is_logged_in && ! $has_query ) {' . PHP_EOL .
			'	wppo_serve_cache_file( $file_path, $gzip_file_path, $brotli_file_path, $cache_life, $accept_encoding );' . PHP_EOL .
			'}' . PHP_EOL . PHP_EOL .
			'if ( $is_logged_in && ! $has_query ) {' . PHP_EOL .
			'	$role_hash = isset( $_COOKIE[\'wppo_role_hash\'] ) ? preg_replace( \'/[^a-f0-9]/\', \'\', $_COOKIE[\'wppo_role_hash\'] ) : \'\';' . PHP_EOL .
			'	if ( \'\' !== $role_hash ) {' . PHP_EOL .
			'		$role_file_path = preg_replace( \'/index\\.html$/\', \'index-\' . $role_hash . \'.html\', $file_path );' . PHP_EOL .
			'		$role_gzip_path = $role_file_path . \'.gz\';' . PHP_EOL .
			'		$role_brotli_path = $role_file_path . \'.br\';' . PHP_EOL .
			'		wppo_serve_cache_file( $role_file_path, $role_gzip_path, $role_brotli_path, $cache_life, $accept_encoding );' . PHP_EOL .
			'	}' . PHP_EOL .
			'}' . PHP_EOL;

			// Write the handler file atomically: tmp-plus-rename with backup and
			// post-write marker verification, so a crash never leaves a truncated drop-in.
			$written = self::atomic_write_dropin( $handler_code );

			// The drop-in changed — System Info's cached ownership verdict is
			// stale (audit #888 finding 25).
			if ( $written && is_callable( array( 'PerformanceOptimise\Inc\System_Info', 'flush_dropin_cache' ) ) ) {
				System_Info::flush_dropin_cache();
			}

			return $written;
		}

		/**
		 * Removes the advanced-cache.php file.
		 *
		 * Deletes the advanced-cache.php file if it exists, along with the
		 * `.wppo-backup` sibling and any orphaned `.tmp.*` siblings from
		 * crashed atomic writes, so no stale artifacts are left behind.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public static function remove(): void {
			global $wp_filesystem;

			$handler_file = self::get_dropin_path();

			if ( ! Util::init_filesystem() ) {
				return;
			}

			if ( ! $wp_filesystem->exists( $handler_file ) ) {
				self::cleanup_dropin_artifacts();
				return;
			}

			if ( ! self::is_our_dropin() ) {
				return;
			}

			$deleted = (bool) $wp_filesystem->delete( $handler_file );

			if ( $deleted ) {
				self::cleanup_dropin_artifacts();
			}

			// The drop-in changed — System Info's cached ownership verdict is
			// stale (audit #888 finding 25). Only flush on a successful delete,
			// mirroring create().
			if ( $deleted && class_exists( 'PerformanceOptimise\Inc\System_Info' ) ) {
				System_Info::flush_dropin_cache();
			}
		}

		/**
		 * Delete the backup sibling and any orphaned tmp siblings.
		 *
		 * Best-effort only: failures are ignored so remove() never fails
		 * because of stale artifacts.
		 *
		 * @since NEXT
		 * @return void
		 */
		private static function cleanup_dropin_artifacts(): void {
			global $wp_filesystem;

			if ( ! is_object( $wp_filesystem ) || ! method_exists( $wp_filesystem, 'delete' ) ) {
				return;
			}

			try {
				$wp_filesystem->delete( self::get_dropin_backup_path() );

				if ( ! method_exists( $wp_filesystem, 'dirlist' ) || ! method_exists( $wp_filesystem, 'exists' ) ) {
					return;
				}

				$content_dir = wp_normalize_path( WP_CONTENT_DIR );
				if ( ! $wp_filesystem->exists( $content_dir ) ) {
					return;
				}

				$listing = $wp_filesystem->dirlist( $content_dir );
				if ( ! is_array( $listing ) ) {
					return;
				}

				foreach ( $listing as $name => $info ) {
					if ( 0 === strpos( (string) $name, 'advanced-cache.php.tmp.' ) ) {
						$wp_filesystem->delete( $content_dir . '/' . $name );
					}
				}
			} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort artifact cleanup must never throw.
			}
		}
	}
}
