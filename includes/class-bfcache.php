<?php
/**
 * Bfcache for logged-in users — Instant Back/Forward.
 *
 * Privacy-safe session-token invalidation per Performance Lab Instant Back/Forward:
 * a random session token is stored in the user session (WP_Session_Tokens) and
 * mirrored in a non-HttpOnly cookie `wordpress_bfcache_session_{COOKIEHASH}`.
 * The authenticated HTML embeds the token (initialSessionToken). On pageshow with
 * persisted=true (bfcache restore) and on immediate execution (HTTP cache), the
 * current cookie token is compared with the initial token; on mismatch the page
 * is cleared and reloaded. The `Cache-Control: no-store` directive is stripped
 * for opted-in sessions and replaced with `private, no-cache, max-age=0,
 * must-revalidate` so proxies never cache authenticated responses while browsers
 * may use bfcache/HTTP cache and still invalidate privacy-safely.
 *
 * Gated by `bfcache.enabled` (false default) and the `wppo_bfcache_enabled`
 * filter. No new Composer deps; all WP APIs behind function_exists guards.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Bfcache' ) ) {
	/**
	 * Bfcache handler.
	 *
	 * @since NEXT
	 */
	class Bfcache {

		/**
		 * User session key for the bfcache session token.
		 *
		 * @since NEXT
		 * @var string
		 */
		const SESSION_KEY = 'bfcache_session_token';

		/**
		 * Filter to control whether bfcache is enabled.
		 *
		 * @since NEXT
		 * @var string
		 */
		const FILTER_ENABLED = 'wppo_bfcache_enabled';

		/**
		 * Script handle for the inline invalidation script.
		 *
		 * @since NEXT
		 * @var string
		 */
		const SCRIPT_HANDLE = 'wppo-bfcache';

		/**
		 * Whether bfcache handling is enabled.
		 *
		 * Reads `wppo_settings[bfcache][enabled]` (false default) and applies
		 * the `wppo_bfcache_enabled` filter. Also gates on `wp_cache_get_salted`
		 * existence as a proxy for WP 6.9+ object-cache enhancements where
		 * bfcache invalidation via session token is expected to be available.
		 * The function_exists gate is soft: if the salted family is unavailable
		 * the feature still works, but the filter documents the intended WP
		 * version. No hard dependency — pure function_exists guard.
		 *
		 * @since NEXT
		 * @return bool True when bfcache is enabled.
		 */
		public static function is_enabled(): bool {
			$settings = Util::get_settings();
			$enabled  = false;
			if ( isset( $settings['bfcache'] ) && is_array( $settings['bfcache'] ) && isset( $settings['bfcache']['enabled'] ) ) {
				$enabled = (bool) $settings['bfcache']['enabled'];
			}
			/**
			 * Filters whether bfcache (Instant Back/Forward) is enabled.
			 *
			 * @since NEXT
			 * @param bool $enabled Whether bfcache is enabled.
			 */
			$enabled = (bool) apply_filters( self::FILTER_ENABLED, $enabled );
			return $enabled;
		}

		/**
		 * Get the cookie name for the bfcache session token.
		 *
		 * Incorporates COOKIEHASH to prevent collisions on multisite subdirectory installs.
		 *
		 * @since NEXT
		 * @return string Cookie name.
		 */
		public static function get_cookie_name(): string {
			if ( defined( 'COOKIEHASH' ) ) {
				return 'wordpress_bfcache_session_' . COOKIEHASH;
			}
			$hash = md5( defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : ( defined( 'SITECOOKIEPATH' ) ? SITECOOKIEPATH : 'wppo' ) );
			return 'wordpress_bfcache_session_' . $hash;
		}

		/**
		 * Generate a bfcache session token.
		 *
		 * @since NEXT
		 * @return string Token (43 chars).
		 */
		public static function generate_token(): string {
			if ( function_exists( 'wp_generate_password' ) ) {
				return wp_generate_password( 43, false, false );
			}
			return bin2hex( random_bytes( 16 ) );
		}

		/**
		 * Get the bfcache session token for a user/session.
		 *
		 * @since NEXT
		 * @param int|null    $user_id User ID, defaults to current.
		 * @param string|null $session_token Session token, defaults to current.
		 * @return string|null Token or null.
		 */
		public static function get_user_token( ?int $user_id = null, ?string $session_token = null ): ?string {
			if ( ! class_exists( 'WP_Session_Tokens' ) ) {
				return null;
			}
			if ( ! function_exists( 'is_user_logged_in' ) || ( ! is_user_logged_in() && null === $user_id && null === $session_token ) ) {
				return null;
			}
			if ( null === $user_id && function_exists( 'get_current_user_id' ) ) {
				$user_id = get_current_user_id();
			}
			if ( empty( $user_id ) ) {
				return null;
			}
			if ( null === $session_token && function_exists( 'wp_get_session_token' ) ) {
				$session_token = wp_get_session_token();
			}
			if ( empty( $session_token ) ) {
				return null;
			}
			try {
				$instance = \WP_Session_Tokens::get_instance( (int) $user_id );
				$session  = $instance->get( $session_token );
			} catch ( \Throwable $e ) {
				return null;
			}
			if ( is_array( $session ) && isset( $session[ self::SESSION_KEY ] ) && is_string( $session[ self::SESSION_KEY ] ) && '' !== $session[ self::SESSION_KEY ] ) {
				return $session[ self::SESSION_KEY ];
			}
			return null;
		}

		/**
		 * Attach bfcache session information on login.
		 *
		 * Hooks into `attach_session_information`. When bfcache is enabled,
		 * generates a random session token for the new session.
		 *
		 * @since NEXT
		 * @param mixed $session Session array.
		 * @return array Session array.
		 */
		public static function attach_session_information( $session ): array {
			if ( ! is_array( $session ) ) {
				$session = array();
			}
			if ( ! self::is_enabled() ) {
				return $session;
			}
			// Only generate if not already present.
			if ( isset( $session[ self::SESSION_KEY ] ) && is_string( $session[ self::SESSION_KEY ] ) && '' !== $session[ self::SESSION_KEY ] ) {
				return $session;
			}
			$session[ self::SESSION_KEY ] = self::generate_token();
			return $session;
		}

		/**
		 * Whether the logged_in cookie should be secure (copied from core logic).
		 *
		 * @since NEXT
		 * @param int $user_id User ID.
		 * @return bool
		 */
		private static function is_logged_in_cookie_secure( int $user_id ): bool {
			$secure                  = function_exists( 'is_ssl' ) ? is_ssl() : false;
			$home                    = function_exists( 'get_option' ) ? get_option( 'home' ) : '';
			$secure_logged_in_cookie = $secure && is_string( $home ) && 'https' === ( function_exists( 'wp_parse_url' ) ? wp_parse_url( $home, PHP_URL_SCHEME ) : wp_parse_url( $home, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.DiscouragedFunctions.parse_url_parse_url -- fallback unreachable, wp_parse_url always exists when is_ssl does, kept for completeness
			$secure                  = apply_filters( 'secure_auth_cookie', $secure, $user_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			return (bool) apply_filters( 'secure_logged_in_cookie', $secure_logged_in_cookie, $user_id, $secure ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}

		/**
		 * Set the bfcache session token cookie.
		 *
		 * @since NEXT
		 * @param int    $user_id User ID.
		 * @param string $token Token.
		 * @param int    $expire Expiration timestamp.
		 * @return void
		 */
		public static function set_token_cookie( int $user_id, string $token, int $expire ): void {
			$cookie_name = self::get_cookie_name();
			$secure      = self::is_logged_in_cookie_secure( $user_id );
			$path        = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
			$domain      = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
			$site_path   = defined( 'SITECOOKIEPATH' ) ? SITECOOKIEPATH : $path;
			setcookie( $cookie_name, $token, $expire, $path, $domain, $secure, false );
			if ( $site_path !== $path ) {
				setcookie( $cookie_name, $token, $expire, $site_path, $domain, $secure, false );
			}
		}

		/**
		 * Hook: set_logged_in_cookie — mirror the session token into a readable cookie.
		 *
		 * @since NEXT
		 * @param string $logged_in_cookie Cookie value (unused).
		 * @param int    $expire Expiration.
		 * @param int    $expiration Expiration.
		 * @param int    $user_id User ID.
		 * @param string $scheme Scheme.
		 * @param string $token Session token.
		 * @return void
		 */
		public static function on_set_logged_in_cookie( string $logged_in_cookie, int $expire, int $expiration, int $user_id, string $scheme, string $token ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			if ( ! self::is_enabled() ) {
				return;
			}
			$bfcache_token = self::get_user_token( $user_id, $token );
			if ( null !== $bfcache_token ) {
				self::set_token_cookie( $user_id, $bfcache_token, $expiration );
			}
		}

		/**
		 * Hook: clear_auth_cookie — clear the bfcache session token cookie on logout.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function on_clear_auth_cookie(): void {
			$cookie_name = self::get_cookie_name();
			$path        = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
			$domain      = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
			$site_path   = defined( 'SITECOOKIEPATH' ) ? SITECOOKIEPATH : $path;
			setcookie( $cookie_name, ' ', time() - YEAR_IN_SECONDS, $path, $domain, false, false );
			if ( $site_path !== $path ) {
				setcookie( $cookie_name, ' ', time() - YEAR_IN_SECONDS, $site_path, $domain, false, false );
			}
		}

		/**
		 * Filter nocache_headers to allow bfcache while preserving privacy.
		 *
		 * Strips `no-store` (and `public`) and ensures `private, no-cache, max-age=0, must-revalidate`
		 * so proxies never cache authenticated responses while browsers may use bfcache/HTTP cache.
		 * Only runs when bfcache is enabled and the current session has a token.
		 *
		 * @since NEXT
		 * @param mixed $headers Headers array.
		 * @return array Headers.
		 */
		public static function filter_nocache_headers( $headers ): array {
			if ( ! is_array( $headers ) ) {
				$headers = array();
			}
			if ( ! isset( $headers['Cache-Control'] ) || ! is_string( $headers['Cache-Control'] ) ) {
				return $headers;
			}
			if ( ! self::is_enabled() ) {
				return $headers;
			}
			// Only strip no-store for sessions that opted into bfcache (have a token).
			$token = self::get_user_token();
			if ( null === $token ) {
				return $headers;
			}
			// Ensure cookie present (repair if deleted mid-session).
			$cookie_name = self::get_cookie_name();
			if ( ! isset( $_COOKIE[ $cookie_name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( function_exists( 'get_current_user_id' ) ) {
					$user_id = get_current_user_id();
					if ( $user_id > 0 ) {
						self::set_token_cookie( (int) $user_id, $token, time() + 14 * DAY_IN_SECONDS );
					}
				}
			}

			$directives = (array) preg_split( '/\s*,\s*/', $headers['Cache-Control'] );
			if ( ! in_array( 'no-store', $directives, true ) ) {
				return $headers;
			}
			$directives               = array_diff( $directives, array( 'no-store', 'public' ) );
			$directives               = array_unique(
				array_merge(
					$directives,
					array( 'private', 'no-cache', 'max-age=0', 'must-revalidate' )
				)
			);
			$headers['Cache-Control'] = implode( ', ', $directives );
			return $headers;
		}

		/**
		 * Enqueue/print the bfcache invalidation script for authenticated pages.
		 *
		 * Prints an inline script that compares the initial token (embedded in HTML)
		 * with the current cookie token. On mismatch (logout or user switch) the page
		 * is cleared and reloaded. Handles both HTTP cache (immediate check) and
		 * bfcache (pageshow with persisted=true). Uses vanilla JS, no dependencies.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function enqueue_scripts(): void {
			if ( ! self::is_enabled() ) {
				return;
			}
			if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
				return;
			}
			$token = self::get_user_token();
			if ( null === $token ) {
				return;
			}
			if ( function_exists( 'is_admin' ) && is_admin() ) {
				return;
			}
			// Avoid duplicate output.
			static $done = false;
			if ( $done ) {
				return;
			}
			$done = true;

			$cookie_name = self::get_cookie_name();
			// Inline script: privacy-safe invalidation.
			// Use wp_print_inline_script_tag if available (WP 6.0+), else echo.
			$js = sprintf(
				'(function(){var c=%s,t=%s,q="wppo_bfcache_reloaded";function g(){var r=new RegExp("(?:^|;\\s*)"+c+"=([^;]+)");var m=document.cookie.match(r);return m?decodeURIComponent(m[1]):null}function i(){var u=new URL(window.location.href);if(u.searchParams.has(q))return;document.documentElement.style.opacity="0";try{document.documentElement.innerHTML=""}catch(e){}u.searchParams.set(q,String(Math.random()));history.replaceState({},\"\",u.href);window.location.reload()}function h(e){if(e.persisted&&t!==g()){i();return}var u=new URL(window.location.href);if(u.searchParams.has(q)){u.searchParams.delete(q);history.replaceState({},\"\",u.href)}}if(t!==g()){i()}else{window.addEventListener("pageshow",h)}})();',
				wp_json_encode( $cookie_name ),
				wp_json_encode( $token )
			);

			if ( function_exists( 'wp_print_inline_script_tag' ) ) {
				add_action(
					'wp_footer',
					static function () use ( $js ) {
						wp_print_inline_script_tag( $js, array( 'id' => 'wppo-bfcache-invalidation' ) );
					},
					20
				);
			} else {
				add_action(
					'wp_footer',
					static function () use ( $js, $cookie_name, $token ) {
						echo '<script id="wppo-bfcache-invalidation">' . $js . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					},
					20
				);
			}
			// Also print in wp_head for early invalidation on HTTP cache restore (alternative to footer).
			// The footer hook above is sufficient for bfcache; HTTP cache immediate check also runs there before DOM ready.
		}

		/**
		 * Register hooks.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function init(): void {
			add_filter( 'attach_session_information', array( self::class, 'attach_session_information' ) );
			add_action( 'set_logged_in_cookie', array( self::class, 'on_set_logged_in_cookie' ), 10, 6 );
			add_action( 'clear_auth_cookie', array( self::class, 'on_clear_auth_cookie' ) );
			add_filter( 'nocache_headers', array( self::class, 'filter_nocache_headers' ), 1000 );
			add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_scripts' ) );
			// Also for admin and customize (like upstream) so admin pages get bfcache too.
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_scripts' ) );
		}
	}
}
