<?php
/**
 * LiteSpeed ESI bridge (P5).
 *
 * Enterprise only — OLS has no ESI per litespeed-research.md:135.
 * Provides nonce/widget hole-punching via litespeed_nonce / litespeed_esi_nonces,
 * and AJAX fallback on OLS with DONOTCACHEPAGE.
 *
 * @package PerformanceOptimise\Inc
 * @since 2.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\LiteSpeed_ESI' ) ) {

	/**
	 * Class LiteSpeed_ESI
	 *
	 * @since 2.0.0
	 */
	final class LiteSpeed_ESI {

		/**
		 * Memoized build/esi.asset.php lookup (false when missing).
		 *
		 * Class property (instead of a function-static) so unit tests and
		 * long-lived PHP workers can reset it via reset_asset_cache_for_tests()
		 * — a first missing-artifact false must not stick forever in-process.
		 *
		 * @since NEXT
		 * @var mixed
		 */
		private static $asset_cache = null;

		/**
		 * Whether $asset_cache was already populated.
		 *
		 * Null is a legitimate "not yet loaded" state distinct from a loaded
		 * false, hence the separate flag.
		 *
		 * @since NEXT
		 * @var bool
		 */
		private static bool $asset_cache_set = false;

		/**
		 * Reset the memoized asset lookup (tests / long-lived workers).
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_asset_cache_for_tests(): void {
			self::$asset_cache         = null;
			self::$asset_cache_set     = false;
			self::$nonce_wildcard_memo = array();
		}

		/**
		 * Per-request memo of confirmed ESI wildcard allowlist keys.
		 *
		 * The nonce injector runs on every fragment hydration (including
		 * valid-nonce hydrations that skip the throttle); the memo skips
		 * the repeated get_transient() once this request has confirmed (or
		 * written) the wildcard. Keyed by transient key. Reset by
		 * reset_asset_cache_for_tests().
		 *
		 * @since NEXT
		 * @var array<string,bool>
		 */
		private static $nonce_wildcard_memo = array();

		/**
		 * Whether ESI is available (Enterprise only).
		 *
		 * Checks LITESPEED_SERVER_TYPE, LITESPEED_ESI_ON, or litespeed_esi_status filter.
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		public static function is_esi_available(): bool {
			if ( ! class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) || ! LiteSpeed_Integration::is_litespeed() ) {
				return false;
			}
			// OLS has no ESI — gate by Enterprise check.
			if ( defined( 'LITESPEED_SERVER_TYPE' ) && 'OLS' === LITESPEED_SERVER_TYPE ) {
				return false;
			}
			if ( defined( 'LITESPEED_ESI_ON' ) && LITESPEED_ESI_ON ) {
				return true;
			}
			if ( has_filter( 'litespeed_esi_status' ) && apply_filters( 'litespeed_esi_status', false ) ) {
				return true;
			}
			/**
			 * Filter whether ESI is available (primary).
			 *
			 * @since 2.0.0
			 * @param bool $available Whether ESI is available.
			 */
			$available = (bool) apply_filters( 'wppo_litespeed_esi_available', false );
			if ( $available ) {
				return true;
			}
			/**
			 * Filter whether ESI is available (legacy alias).
			 *
			 * @since 2.0.0
			 * @param bool $available Whether ESI is available.
			 */
			return (bool) apply_filters( 'wppo_esi_available', false );
		}

		/**
		 * Whether the ESI bridge setting is enabled.
		 *
		 * Raw setting + filter only — unlike is_enabled() this does not require
		 * Enterprise ESI availability, so it also covers the OLS AJAX-fallback
		 * placeholder mode where the server renders <div data-wppo-esi> holes
		 * and src/esi.js hydrates them via the wppo_esi_fragment endpoint
		 * (audit #898).
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		public static function is_setting_enabled(): bool {
			$options = Util::get_settings();
			$enabled = ! empty( $options['litespeed_integration']['esi']['enabled'] );
			/**
			 * Filter whether the ESI bridge setting is enabled.
			 *
			 * @since 2.0.0
			 * @param bool $enabled Whether the ESI setting is enabled.
			 */
			return (bool) apply_filters( 'wppo_esi_enabled', $enabled );
		}

		/**
		 * Whether ESI is enabled via settings AND available (Enterprise).
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		public static function is_enabled(): bool {
			return self::is_setting_enabled() && self::is_esi_available();
		}

		/**
		 * Whether the Header_Emitter bridge is loaded.
		 *
		 * Single guard for every Header_Emitter call site below: the ESI
		 * bridge can run on admin-ajax/early hooks where the emitter file
		 * may not be loaded yet, so header emission must fail closed.
		 *
		 * @since NEXT
		 * @return bool True when Header_Emitter can be called safely.
		 */
		private static function has_header_emitter(): bool {
			return class_exists( 'PerformanceOptimise\Inc\Header_Emitter' );
		}

		/**
		 * Per-IP-per-block fixed-window throttle for the public fragment endpoint.
		 *
		 * The handler is registered for nopriv (guests hydrate cart/nonce
		 * holes through it). Invalid-nonce attempts (60/60s per IP+block)
		 * and `nonce`-block refreshes (tighter 10/60s per IP+block, since
		 * each refresh mints a fresh nonce) are throttled, and valid
		 * hydrations are throttled at a higher bound (120/60s per
		 * IP+block) so a scraped semi-public nonce cannot grant unlimited
		 * fragment hits. The bucket is keyed by
		 * client IP plus block name so one hot block cannot exhaust the
		 * budget for the others, mirroring
		 * Rest::is_endpoint_throttled() semantics: 60 hits per 60s; the TTL
		 * is set on the first increment and later hits re-store with the
		 * remaining TTL instead of extending it. Fail-open when the transient
		 * API is unavailable (early boot or unit stubs): the nonce gate still
		 * applies. REMOTE_ADDR only (never X-Forwarded-For): the header is
		 * client-controlled and must not mint fresh buckets.
		 *
		 * @since NEXT
		 * @param string $block  Block name scoping the bucket ('' for legacy callers).
		 * @param int    $limit  Max hits per window.
		 * @param int    $window Window in seconds.
		 * @return bool True when throttled.
		 */
		private static function is_fragment_throttled( string $block = '', int $limit = 60, int $window = 60 ): bool {
			if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
				return false;
			}
			try {
				$ip = '';
				if ( isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				}
				$key    = Util::transient_key( 'wppo_esi_throttle_' . md5( ( '' !== $ip ? $ip : 'anon' ) . '|' . strtolower( $block ) ) );
				$bucket = get_transient( $key );
				$now    = time();
				if ( ! is_array( $bucket ) || ! isset( $bucket['count'], $bucket['start'] ) || (int) $bucket['start'] > $now || ( $now - (int) $bucket['start'] ) >= $window ) {
					set_transient(
						$key,
						array(
							'count' => 1,
							'start' => $now,
						),
						$window
					);
					return false;
				}
				$count = (int) $bucket['count'];
				if ( $count >= $limit ) {
					return true;
				}
				$elapsed   = $now - (int) $bucket['start'];
				$remaining = max( 1, min( $window, $window - $elapsed ) );
				set_transient(
					$key,
					array(
						'count' => $count + 1,
						'start' => (int) $bucket['start'],
					),
					$remaining
				);
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether a presented nonce value proves prior page receipt.
		 *
		 * Structural evidence only (audit #1329): `wp_create_nonce()` mints 10
		 * alphanumerics, so a well-formed value shows the caller scraped a
		 * server-minted placeholder — even when the tick window has expired and
		 * `wp_verify_nonce()` can no longer distinguish it from garbage. Used
		 * to tier the `nonce`-block refresh budget, not to authorize.
		 *
		 * @since NEXT
		 * @param string $nonce The presented nonce value (may be empty).
		 * @return bool True when the value is structurally a WP nonce.
		 */
		private static function has_nonce_receipt_proof( string $nonce ): bool {
			return (bool) preg_match( '/^[a-zA-Z0-9]{10}$/', $nonce );
		}

		/**
		 * Emit the private pair, fail-closed when the emitter is unavailable.
		 *
		 * The ESI bridge can run on admin-ajax/early hooks where the emitter
		 * file may not be loaded yet — silently skipping would leave a
		 * cart/checkout/adminbar response publicly cacheable, so fall back
		 * to direct PHP header() emission instead of skipping.
		 *
		 * @since NEXT
		 * @return void
		 */
		private static function emit_private_fail_closed(): void {
			if ( self::has_header_emitter() ) {
				Header_Emitter::emit_private_pair();
				return;
			}
			if ( function_exists( 'headers_sent' ) && headers_sent() ) {
				return;
			}
			header( 'Cache-Control: private,no-cache' );
			header( 'X-LiteSpeed-Cache-Control: private,no-vary' );
		}

		/**
		 * Emit the no-cache pair, fail-closed when the emitter is unavailable.
		 *
		 * Same fail-closed contract as emit_private_fail_closed() for the
		 * admin / no-cache path.
		 *
		 * @since NEXT
		 * @return void
		 */
		private static function emit_nocache_fail_closed(): void {
			if ( self::has_header_emitter() ) {
				Header_Emitter::emit_nocache_pair();
				return;
			}
			if ( function_exists( 'headers_sent' ) && headers_sent() ) {
				return;
			}
			header( 'Cache-Control: no-cache' );
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}

		/**
		 * Fallback nonce seed when pluggable functions are unavailable.
		 *
		 * Prefers wp_salt() when it exists; otherwise uses a per-install
		 * secret persisted in the options table so the fallback nonce is
		 * not md5(block + static-string) predictable from source. When no
		 * persistence is available either, returns an empty string and
		 * callers must treat the nonce as invalid (fail closed).
		 *
		 * @since NEXT
		 * @param string $context Nonce context (block name).
		 * @return string MD5 seed for the fallback nonce, or '' when no secret exists.
		 */
		private static function fallback_nonce_seed( string $context ): string {
			if ( function_exists( 'wp_salt' ) ) {
				return md5( $context . wp_salt() );
			}
			$secret = function_exists( 'get_option' ) ? get_option( 'wppo_esi_fallback_secret', '' ) : '';
			if ( ! is_string( $secret ) || '' === $secret ) {
				try {
					$secret = function_exists( 'wp_generate_password' ) ? wp_generate_password( 64, true, true ) : bin2hex( random_bytes( 32 ) );
				} catch ( \Throwable $e ) {
					unset( $e );
					return '';
				}
				if ( function_exists( 'add_option' ) ) {
					// First writer wins: concurrent first-use requests must not
					// last-write-wins invalidate each other's fallback nonces, so
					// add (which fails when the option already exists) before
					// falling back to update.
					$added = add_option( 'wppo_esi_fallback_secret', $secret, '', false );
					if ( ! $added ) {
						$winner = function_exists( 'get_option' ) ? get_option( 'wppo_esi_fallback_secret', '' ) : '';
						if ( is_string( $winner ) && '' !== $winner ) {
							$secret = $winner;
						} elseif ( function_exists( 'update_option' ) ) {
							update_option( 'wppo_esi_fallback_secret', $secret, false );
						} else {
							return '';
						}
					}
				} elseif ( function_exists( 'update_option' ) ) {
					update_option( 'wppo_esi_fallback_secret', $secret, false );
				} else {
					return '';
				}
			}
			return md5( $context . $secret );
		}

		/**
		 * Whether core supports the native `strategy` script args (WP 6.3+).
		 *
		 * Delegates to Util::supports_script_strategy() — the single shared
		 * home for this gate (also used by RUM::supports_script_strategy())
		 * so floor bumps cannot drift between copies.
		 *
		 * @since NEXT
		 * @return bool
		 */
		private static function supports_script_strategy(): bool {
			return Util::supports_script_strategy();
		}

		/**
		 * Enqueue the ESI hydration client (build/esi.js) for OLS placeholder mode.
		 *
		 * On OpenLiteSpeed there is no native ESI, so the server renders
		 * `<div data-wppo-esi="…">` placeholders (render_esi_placeholder()) that
		 * src/esi.js hydrates via the wppo_esi_fragment admin-ajax endpoint.
		 * Without this client those placeholders stay empty forever (audit #898).
		 *
		 * Gates mirror the placeholder path exactly:
		 *
		 * - ESI setting enabled (is_setting_enabled()) — same toggle + filter the
		 *   bridge itself uses;
		 * - LiteSpeed-family server (is_litespeed()) with a non-standalone
		 *   effective mode — identical to the gates in should_punch_hole()/init();
		 * - NOT Enterprise ESI (is_esi_available()) — on LSWS Enterprise the
		 *   server hydrates `<esi:include>` server-side, so the JS client must
		 *   not load.
		 *
		 * Enqueue mirrors RUM::maybe_enqueue_scripts(): build/esi.asset.php
		 * supplies the version + dependency list, with a deferred footer
		 * enqueue on WP 6.3+ (native `strategy: defer`) and the classic
		 * bool $in_footer fallback on older core — this client is plain
		 * vanilla JS, not a script module.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function enqueue_hydration_client(): void {
			if ( is_admin() ) {
				return;
			}
			if ( ! self::is_setting_enabled() ) {
				return;
			}
			if ( ! class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) || ! LiteSpeed_Integration::is_litespeed() ) {
				return;
			}
			// Standalone mode means "ignore LiteSpeed" — no ESI at all (same
			// try/catch gate as should_punch_hole()).
			if ( method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'effective_mode' ) ) {
				try {
					$mode = LiteSpeed_Integration::effective_mode();
					if ( 'standalone' === $mode ) {
						return;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			// Enterprise native ESI hydrates server-side via <esi:include> — the
			// JS client must not load there.
			if ( self::is_esi_available() ) {
				return;
			}

			$asset_file = WPPO_PLUGIN_PATH . 'build/esi.asset.php';
			$deps       = array( 'wp-i18n' );
			$version    = WPPO_VERSION;
			// Memoize the asset lookup: this runs on every frontend request on
			// LiteSpeed sites, so stat + include the artifact at most once per
			// request instead of paying a second per-page stat. Resettable via
			// reset_asset_cache_for_tests() for tests/long-lived workers.
			if ( ! self::$asset_cache_set ) {
				self::$asset_cache     = file_exists( $asset_file ) ? include $asset_file : false; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
				self::$asset_cache_set = true;
			}
			if ( false !== self::$asset_cache ) {
				$asset = self::$asset_cache;
				if ( is_array( $asset ) ) {
					// Trust-but-verify the build artifact: entries must be
					// non-empty strings (a tampered partial artifact must not
					// widen the dependency trust surface), with a wp-i18n
					// fallback since src/esi.js needs it for translated labels.
					$raw_deps = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
					$clean    = array();
					foreach ( $raw_deps as $dep ) {
						// Only non-empty strings are valid script deps: a
						// tampered artifact's true/1 would otherwise enqueue
						// a bogus '1' dependency.
						if ( ! is_string( $dep ) ) {
							continue;
						}
						$dep = trim( $dep );
						if ( '' !== $dep ) {
							$clean[] = $dep;
						}
					}
					$clean = array_values( array_unique( $clean ) );
					// Always union wp-i18n: src/esi.js needs it for translated
					// labels, so a future build emitting non-empty deps without
					// wp-i18n must not load without its i18n runtime.
					if ( ! in_array( 'wp-i18n', $clean, true ) ) {
						$clean[] = 'wp-i18n';
					}
					$deps = $clean;
					if ( isset( $asset['version'] ) ) {
						if ( is_string( $asset['version'] ) && '' !== $asset['version'] ) {
							$version = $asset['version'];
						} elseif ( is_int( $asset['version'] ) || is_float( $asset['version'] ) ) {
							$version = (string) $asset['version'];
						}
					}
				}
			} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Visible failure signal for partial deploys: src/esi.js
				// imports @wordpress/i18n for translated aria-labels, so a
				// missing asset file would otherwise ship an untranslated or
				// broken hydration client with no log.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostics, gated above.
				error_log( 'WPPO ESI: build/esi.asset.php missing; falling back to wp-i18n dependencies.' );
			}

			if ( self::supports_script_strategy() ) {
				wp_enqueue_script(
					'wppo-esi',
					WPPO_PLUGIN_URL . 'build/esi.js',
					$deps,
					$version,
					array(
						'strategy'  => 'defer',
						'in_footer' => true,
					)
				);
				return;
			}

			// Bool $in_footer fallback so the client still loads in the
			// footer on WP < 6.3 (fail-open: still hydrates, just
			// render-blocking).
			wp_enqueue_script( 'wppo-esi', WPPO_PLUGIN_URL . 'build/esi.js', $deps, $version, true );
		}

		/**
		 * Whether a given context should punch hole via AJAX/ESI.
		 *
		 * Gated by is_litespeed() && !is_esi_available() plus Woo / auth context.
		 *
		 * @since 2.0.0
		 * @param string $context Context: cart|checkout|account|adminbar|nonce etc.
		 * @return bool
		 */
		public static function should_punch_hole( string $context ): bool {
			if ( ! class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) || ! LiteSpeed_Integration::is_litespeed() ) {
				return false;
			}
			// Gate by effective_mode litespeed check — standalone means no ESI.
			if ( method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'effective_mode' ) ) {
				try {
					$mode = LiteSpeed_Integration::effective_mode();
					if ( 'standalone' === $mode ) {
						return false;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( self::is_esi_available() ) {
				return false;
			}
			/**
			 * Filter whether hole-punch should be active for context.
			 *
			 * @since 2.0.0
			 * @param bool   $punch   Whether to punch.
			 * @param string $context Context name.
			 */
			$filtered = apply_filters( 'wppo_esi_should_punch_hole', null, $context );
			if ( null !== $filtered ) {
				return (bool) $filtered;
			}
			$context = strtolower( sanitize_text_field( $context ) );
			switch ( $context ) {
				case 'cart':
					if ( function_exists( 'is_cart' ) ) {
						try {
							if ( is_cart() ) {
								return true;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
					// Unslash + sanitize raw cookie input before trusting it.
					// is_string guards: an array-valued cookie would otherwise
					// reach wp_unslash()/sanitize_text_field() as an array.
					$cart_cookie = isset( $_COOKIE['woocommerce_items_in_cart'] ) && is_string( $_COOKIE['woocommerce_items_in_cart'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['woocommerce_items_in_cart'] ) ) : '';
					$hash_cookie = isset( $_COOKIE['woocommerce_cart_hash'] ) && is_string( $_COOKIE['woocommerce_cart_hash'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['woocommerce_cart_hash'] ) ) : '';
					if ( '' !== $cart_cookie || '' !== $hash_cookie ) {
						return true;
					}
					return false;
				case 'checkout':
					if ( function_exists( 'is_checkout' ) ) {
						try {
							if ( is_checkout() ) {
								return true;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
					return false;
				case 'account':
				case 'my-account':
				case 'my_account':
					if ( function_exists( 'is_account_page' ) ) {
						try {
							if ( is_account_page() ) {
								return true;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
					return false;
				case 'adminbar':
				case 'admin_bar':
				case 'admin-bar':
					if ( function_exists( 'is_user_logged_in' ) ) {
						try {
							if ( is_user_logged_in() ) {
								return true;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
					return false;
				case 'nonce':
					if ( function_exists( 'is_user_logged_in' ) ) {
						try {
							if ( is_user_logged_in() ) {
								return true;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
					// Also punch for cart pages even if guest (nonce for cart).
					if ( function_exists( 'is_cart' ) ) {
						try {
							if ( is_cart() ) {
								return true;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
					return false;
				default:
					// Generic: any private context triggers.
					if ( function_exists( 'is_cart' ) ) {
						try {
							if ( is_cart() ) {
								return true;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
					if ( function_exists( 'is_checkout' ) ) {
						try {
							if ( is_checkout() ) {
								return true;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
					if ( function_exists( 'is_account_page' ) ) {
						try {
							if ( is_account_page() ) {
								return true;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
					if ( function_exists( 'is_user_logged_in' ) ) {
						try {
							if ( is_user_logged_in() ) {
								return true;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
					return false;
			}
		}

		/**
		 * Render ESI placeholder.
		 *
		 * Enterprise: <esi:include src="...">; OLS: <div data-wppo-esi="block" data-nonce="...">
		 *
		 * The embedded `wppo_esi` nonce is a per-session CSRF token, not a
		 * secrecy boundary: placeholders rendered before the static-HTML
		 * cache is generated are baked into cache files served to guests for
		 * the nonce lifetime, so the fragment nonce is semi-public by design.
		 * Authorization must not rest on nonce secrecy alone — the
		 * `adminbar` block additionally requires `is_user_logged_in()`, which
		 * is the real boundary, and every fragment passes through wp_kses().
		 *
		 * @since 2.0.0
		 * @param string $block Block name (cart, adminbar, nonce).
		 * @param array  $attrs Optional attributes.
		 * @return string
		 */
		public static function render_esi_placeholder( string $block, array $attrs = array() ): string {
			$block = sanitize_text_field( $block );
			if ( '' === $block ) {
				$block = 'cart';
			}
			/**
			 * Filter ESI block name.
			 *
			 * @since 2.0.0
			 * @param string $block Block name.
			 * @param array  $attrs Attributes.
			 */
			$block = (string) apply_filters( 'wppo_esi_block', $block, $attrs );
			// Re-normalize after the filter: a filter returning a long or
			// path-like string would otherwise create unbounded transient
			// keys (cache poisoning/key squatting) via transient_key()/md5
			// and flow into add_query_arg/esc_attr unsanitized.
			$block = strtolower( sanitize_key( $block ) );
			if ( '' === $block ) {
				$block = 'cart';
			}

			// Fail closed when the nonce API is unavailable: a fallback md5
			// seed can never verify via wp_verify_nonce(), so embedding it
			// would render placeholders that 403 forever.
			if ( ! function_exists( 'wp_create_nonce' ) || ! function_exists( 'wp_verify_nonce' ) ) {
				return '<!-- wppo-esi:unavailable -->';
			}
			$nonce = wp_create_nonce( 'wppo_esi' );
			// Fail closed per fallback_nonce_seed() contract: an empty nonce
			// means no usable secret exists, so never embed it — render an
			// inert marker instead (fragment verification rejects empty
			// nonces, so hydration stays denied).
			if ( ! is_string( $nonce ) || '' === $nonce ) {
				return '<!-- wppo-esi:unavailable -->';
			}
			// Wildcard allowlist key only — the per-nonce transients
			// previously written here were never read by
			// handle_ajax_fragment() (dead stores/write amplification), so
			// they are no longer written. Only set when missing, and
			// memoized per request so N blocks cost at most one transient
			// read (DB-backed transients).
			$wildcard_key = Util::transient_key( 'wppo_esi_nonce_' . $block );
			if ( empty( self::$nonce_wildcard_memo[ $wildcard_key ] ) ) {
				$needs_write = ! function_exists( 'get_transient' ) || false === get_transient( $wildcard_key );
				if ( $needs_write && function_exists( 'set_transient' ) ) {
					set_transient( $wildcard_key, 1, 12 * HOUR_IN_SECONDS );
				}
				self::$nonce_wildcard_memo[ $wildcard_key ] = true;
			}

			$src = '';
			if ( function_exists( 'admin_url' ) ) {
				$src = add_query_arg(
					array(
						'action'   => 'wppo_esi_fragment',
						'block'    => $block,
						'_wpnonce' => $nonce,
					),
					admin_url( 'admin-ajax.php' )
				);
			} else {
				$src = '/wp-admin/admin-ajax.php?action=wppo_esi_fragment&block=' . rawurlencode( $block ) . '&_wpnonce=' . rawurlencode( $nonce );
			}

			$attrs_str = '';
			if ( ! empty( $attrs ) ) {
				foreach ( $attrs as $k => $v ) {
					$attrs_str .= ' ' . esc_attr( (string) $k ) . '="' . esc_attr( (string) $v ) . '"';
				}
			}

			if ( self::is_esi_available() ) {
				$html = sprintf( '<esi:include src="%s"%s />', esc_url( $src ), $attrs_str );
				/**
				 * Filter ESI placeholder HTML.
				 *
				 * @since 2.0.0
				 * @param string $html  Placeholder HTML.
				 * @param string $block Block name.
				 * @param array  $attrs Attributes.
				 */
				$html = (string) apply_filters( 'wppo_esi_placeholder', $html, $block, $attrs );
				/**
				 * Legacy alias for placeholder.
				 *
				 * @since 2.0.0
				 * @param string $html  Placeholder HTML.
				 * @param string $block Block name.
				 */
				$html = (string) apply_filters( 'wppo_litespeed_esi_placeholder', $html, $block );
				return $html;
			}

			// Accessible loading semantics for the OLS fallback hole (audit
			// #888 finding 7): assistive tech must know content is pending.
			// Caller-provided attributes are respected — duplicated HTML
			// attributes make browsers honour the first occurrence, so defaults
			// are only added for keys absent from $attrs. HTML attribute names
			// are ASCII case-insensitive, so the override check is too (Part 2
			// review).
			$attr_keys_lower = array_map( 'strtolower', array_map( 'strval', array_keys( $attrs ) ) );
			$aria_defaults   = self::get_esi_placeholder_aria( $block );
			$aria_str        = '';
			foreach ( $aria_defaults as $attr => $value ) {
				if ( null === $value || in_array( strtolower( (string) $attr ), $attr_keys_lower, true ) ) {
					continue;
				}
				$aria_str .= sprintf( ' %s="%s"', esc_attr( (string) $attr ), esc_attr( (string) $value ) );
			}

			$html = sprintf( '<div data-wppo-esi="%s" data-nonce="%s"%s%s></div>', esc_attr( $block ), esc_attr( $nonce ), $aria_str, $attrs_str );
			/**
			 * Filter ESI placeholder HTML (OLS fallback).
			 *
			 * @since 2.0.0
			 * @param string $html  Placeholder HTML.
			 * @param string $block Block name.
			 * @param array  $attrs Attributes.
			 */
			$html = (string) apply_filters( 'wppo_esi_placeholder', $html, $block, $attrs );
			$html = (string) apply_filters( 'wppo_litespeed_esi_placeholder', $html, $block );
			return $html;
		}

		/**
		 * ARIA attributes for the OLS ESI placeholder.
		 *
		 * The placeholder is an empty hole-punch while the fragment is fetched,
		 * so it announces itself as a busy live region. The invisible `nonce`
		 * marker is hidden from assistive tech instead. Values are defaults
		 * only: callers can override any attribute via $attrs.
		 *
		 * @since 2.0.0
		 * @param string $block Block name.
		 * @return array<string,?string> Attribute => value map (null skips the attribute).
		 */
		private static function get_esi_placeholder_aria( string $block ): array {
			// Strip separators so documented aliases (admin-bar, admin_bar,
			// my-account, my_account) all hit the same label entry (Part 2
			// review).
			$canonical = strtolower( str_replace( array( '_', ' ', '-' ), '', $block ) );

			if ( 'nonce' === $canonical ) {
				return array( 'aria-hidden' => 'true' );
			}

			$labels = array(
				'cart'      => __( 'Loading shopping cart…', 'performance-optimisation' ),
				'checkout'  => __( 'Loading checkout…', 'performance-optimisation' ),
				'account'   => __( 'Loading account menu…', 'performance-optimisation' ),
				'myaccount' => __( 'Loading account menu…', 'performance-optimisation' ),
				'adminbar'  => __( 'Loading admin bar…', 'performance-optimisation' ),
			);

			$default = sprintf(
				/* translators: %s: ESI block name. */
				__( 'Loading %s…', 'performance-optimisation' ),
				$block
			);

			/**
			 * Filters the accessible loading label for an ESI placeholder.
			 *
			 * @since 2.0.0
			 * @param string $label Loading label.
			 * @param string $block Block name.
			 */
			$label = (string) apply_filters( 'wppo_esi_block_label', $labels[ $canonical ] ?? $default, $block );

			return array(
				'role'       => 'status',
				'aria-live'  => 'polite',
				'aria-busy'  => 'true',
				'aria-label' => $label,
			);
		}

		/**
		 * Register AJAX handlers for ESI fragments.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function register_ajax_handlers(): void {
			add_action( 'wp_ajax_wppo_esi_fragment', array( self::class, 'handle_ajax_fragment' ) );
			add_action( 'wp_ajax_nopriv_wppo_esi_fragment', array( self::class, 'handle_ajax_fragment' ) );
		}

		/**
		 * Allowlist of HTML tags/attributes permitted in ESI fragment HTML.
		 *
		 * This is the authoritative server-side sanitization contract: every
		 * fragment returned by handle_ajax_fragment() is inserted into the DOM
		 * client-side (src/esi.js), so it MUST pass through wp_kses() with this
		 * allowlist before being echoed. Script-capable tags are deliberately
		 * excluded; `data-*` and `aria-*` wildcards cover widget metadata.
		 * Third parties can extend (never loosen) it via the
		 * `wppo_esi_allowed_html` filter.
		 *
		 * @since 2.0.0
		 * @return array Allowed tags => attributes map, in wp_kses() shape.
		 */
		public static function get_allowed_fragment_html(): array {
			$global_attrs = array(
				'class'  => true,
				'id'     => true,
				'style'  => true,
				'title'  => true,
				'dir'    => true,
				'lang'   => true,
				'role'   => true,
				'aria-*' => true,
				'data-*' => true,
			);

			$tags = array(
				'a'          => array(
					'href'   => true,
					'rel'    => true,
					'target' => true,
				),
				'b'          => $global_attrs,
				'blockquote' => array( 'cite' => true ) + $global_attrs,
				'br'         => array(),
				'button'     => array(
					'type'     => true,
					'name'     => true,
					'value'    => true,
					'disabled' => true,
				) + $global_attrs,
				'div'        => $global_attrs,
				'em'         => $global_attrs,
				'form'       => array(
					'action'     => true,
					'method'     => true,
					'enctype'    => true,
					'novalidate' => true,
				) + $global_attrs,
				'h1'         => $global_attrs,
				'h2'         => $global_attrs,
				'h3'         => $global_attrs,
				'h4'         => $global_attrs,
				'h5'         => $global_attrs,
				'h6'         => $global_attrs,
				'hr'         => array(),
				'i'          => $global_attrs,
				'img'        => array(
					'src'      => true,
					'srcset'   => true,
					'sizes'    => true,
					'alt'      => true,
					'width'    => true,
					'height'   => true,
					'loading'  => true,
					'decoding' => true,
				) + $global_attrs,
				'input'      => array(
					'type'        => true,
					'name'        => true,
					'value'       => true,
					'placeholder' => true,
					'required'    => true,
					'readonly'    => true,
					'disabled'    => true,
					'min'         => true,
					'max'         => true,
					'step'        => true,
					'checked'     => true,
				) + $global_attrs,
				'label'      => array( 'for' => true ) + $global_attrs,
				'li'         => $global_attrs,
				'nav'        => $global_attrs,
				'ol'         => array( 'start' => true ) + $global_attrs,
				'option'     => array(
					'value'    => true,
					'selected' => true,
					'disabled' => true,
				) + $global_attrs,
				'p'          => $global_attrs,
				'picture'    => $global_attrs,
				'select'     => array(
					'name'     => true,
					'multiple' => true,
					'disabled' => true,
				) + $global_attrs,
				'small'      => $global_attrs,
				'source'     => array(
					'src'    => true,
					'srcset' => true,
					'sizes'  => true,
					'media'  => true,
					'type'   => true,
				) + $global_attrs,
				'span'       => $global_attrs,
				'strong'     => $global_attrs,
				'section'    => $global_attrs,
				'table'      => $global_attrs,
				'tbody'      => $global_attrs,
				'td'         => array(
					'colspan' => true,
					'rowspan' => true,
				) + $global_attrs,
				'th'         => array(
					'colspan' => true,
					'rowspan' => true,
					'scope'   => true,
				) + $global_attrs,
				'thead'      => $global_attrs,
				'time'       => array( 'datetime' => true ) + $global_attrs,
				'tr'         => $global_attrs,
				'ul'         => $global_attrs,
			);

			/**
			 * Filter the wp_kses allowlist applied to ESI fragment HTML.
			 *
			 * The fragment HTML returned by the wppo_esi_fragment AJAX endpoint
			 * is inserted into the page DOM by src/esi.js, so the allowlist is
			 * the server-side sanitization contract. Extend it for custom
			 * widget markup; never add script-capable tags.
			 *
			 * @since 2.0.0
			 * @param array $tags Allowed tags => attributes map (kses shape).
			 */
			$tags = (array) apply_filters( 'wppo_esi_allowed_html', $tags );
			// Denylist-enforce after the filter: a third-party filter adding
			// script-capable tags would otherwise be served from the nopriv
			// ESI endpoint and injected by esi.js for any holder of a
			// semi-public 12h nonce. Protocols are pinned at the wp_kses()
			// call site; tags/attrs are pinned here.
			foreach ( array( 'script', 'iframe', 'object', 'embed', 'link', 'meta', 'style' ) as $denied_tag ) {
				unset( $tags[ $denied_tag ] );
			}
			foreach ( $tags as $tag_name => $tag_attrs ) {
				if ( ! is_array( $tag_attrs ) ) {
					continue;
				}
				foreach ( array_keys( $tag_attrs ) as $attr_name ) {
					$attr_lower = strtolower( (string) $attr_name );
					if ( 0 === strpos( $attr_lower, 'on' ) || 'formaction' === $attr_lower || 'xlink:href' === $attr_lower ) {
						unset( $tags[ $tag_name ][ $attr_name ] );
					}
				}
			}
			return $tags;
		}

		/**
		 * Handle AJAX fragment request.
		 *
		 * Emits Cache-Control: private,no-cache + X-LiteSpeed-Cache-Control: private,no-vary
		 * and returns JSON success with html.
		 *
		 * ## Sanitization contract
		 *
		 * The `html` payload is inserted into the DOM client-side by
		 * src/esi.js (which applies an additional defense-in-depth pass), so
		 * the fragment — including any HTML supplied via the
		 * `wppo_esi_fragment_html` / `wppo_litespeed_esi_fragment_html`
		 * filters — is sanitized through wp_kses() with the allowlist from
		 * get_allowed_fragment_html() before it is echoed. Never return raw
		 * untrusted markup from this endpoint.
		 *
		 * ## Nonce model
		 *
		 * Every block (including `cart`) requires a valid `wppo_esi` nonce,
		 * read from the POST body first with a query-string fallback: the
		 * OLS hydration client (src/esi.js) is POST-only, but Enterprise
		 * `<esi:include>` server-side sub-requests are always GET, so a
		 * POST-only read would 403 every Enterprise hydration. The nonce is
		 * a per-session CSRF token, not a secrecy boundary: placeholders
		 * rendered before the static-HTML cache is generated are baked into
		 * cache files served to guests for the nonce lifetime, so the check
		 * must never be the sole authorization gate (the `adminbar` block
		 * additionally requires `is_user_logged_in()`, which is the real
		 * boundary there). The `nonce` block is refreshable without a valid
		 * presented nonce (rate-limited like invalid attempts) so pages
		 * served from a stale static-HTML cache — whose baked-in nonce has
		 * expired — can mint a fresh one instead of 403ing forever.
		 *
		 * Throttling runs after verification: invalid-nonce attempts
		 * (60/60s per IP+block) plus `nonce` refreshes (tighter 10/60s
		 * per IP+block, fail-closed when the transient API is
		 * unavailable) plus valid hydrations at a higher bound (120/60s
		 * per IP+block).
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function handle_ajax_fragment(): void {
			// POST body first (OLS hydration client), query-string fallback
			// for Enterprise <esi:include> server-side GET sub-requests —
			// matching the _wpnonce read order below so a mixed-source
			// request cannot route one source's block against the other's
			// nonce scope.
			$block = '';
			if ( isset( $_POST['block'] ) && is_string( $_POST['block'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$block = strtolower( sanitize_text_field( wp_unslash( $_POST['block'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			} elseif ( isset( $_GET['block'] ) && is_string( $_GET['block'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$block = strtolower( sanitize_text_field( wp_unslash( $_GET['block'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			if ( '' === $block ) {
				$block = 'cart';
			}

			// POST body first (OLS hydration client); query-string fallback
			// for Enterprise <esi:include> server-side GET sub-requests, whose
			// src URL carries the _wpnonce embedded by render_esi_placeholder().
			$nonce = '';
			if ( isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			} elseif ( isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			$nonce_valid = ( '' !== $nonce && function_exists( 'wp_verify_nonce' ) ) ? wp_verify_nonce( $nonce, 'wppo_esi' ) : false;

			$is_nonce_refresh = ( 'nonce' === $block );

			// The nonce-refresh block stays reachable with a stale/expired
			// presented nonce (stale static-HTML cache recovery), but the
			// minting oracle is rate-limited per IP+block at a tighter
			// bound than invalid attempts: a refresh mints a fresh 12h
			// nonce, so refresh-then-replay must not give unlimited
			// fragment hits. Fail-closed when the transient API is
			// unavailable (is_fragment_throttled() fails open): unlimited
			// minting must not be granted when no bucket can be enforced.
			// Audit #1329: tiered budgets — a caller presenting proof of prior
			// receipt (a structurally valid nonce value, even if expired beyond
			// the wp_verify_nonce() window) keeps the recovery budget; a caller
			// presenting nothing gets a squeezed budget so blind minting without
			// ever receiving a page is throttled harder. Expired-vs-never-valid
			// cannot be distinguished after the tick window, so the structural
			// check (WP nonces are 10 alphanumerics) is receipt evidence only.
			if ( $is_nonce_refresh ) {
				if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
					self::emit_private_fail_closed();
					if ( function_exists( 'wp_send_json_error' ) ) {
						wp_send_json_error( array( 'message' => 'Too many requests. Please try again shortly.' ), 429 );
					}
					return;
				}
				$refresh_limit = self::has_nonce_receipt_proof( $nonce ) ? 10 : 3;
				if ( self::is_fragment_throttled( $block, $refresh_limit, 60 ) ) {
					self::emit_private_fail_closed();
					if ( function_exists( 'wp_send_json_error' ) ) {
						wp_send_json_error( array( 'message' => 'Too many requests. Please try again shortly.' ), 429 );
					}
					return;
				}
			} elseif ( ! $nonce_valid ) {
				// Require a valid 'wppo_esi' nonce for every other block,
				// including the cart fragment. Guests present the nonce
				// embedded in the page placeholder, so hydration keeps
				// working; no block is reachable without proving receipt of
				// a server-minted placeholder. Only invalid attempts are
				// throttled (verify-first, throttle-second).
				if ( self::is_fragment_throttled( $block ) ) {
					self::emit_private_fail_closed();
					if ( function_exists( 'wp_send_json_error' ) ) {
						wp_send_json_error( array( 'message' => 'Too many requests. Please try again shortly.' ), 429 );
					}
					return;
				}
				self::emit_private_fail_closed();
				if ( function_exists( 'wp_send_json_error' ) ) {
					wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
				}
				return;
				// Valid-nonce hydrations are throttled too, at a higher
				// bound: placeholder nonces are baked into static-HTML
				// cache served to guests (semi-public, 12h), so one
				// scraped valid nonce must not grant unlimited fragment
				// hits per IP.
			} elseif ( self::is_fragment_throttled( $block, 120, 60 ) ) {
				self::emit_private_fail_closed();
				if ( function_exists( 'wp_send_json_error' ) ) {
					wp_send_json_error( array( 'message' => 'Too many requests. Please try again shortly.' ), 429 );
				}
				return;
			}

			// For adminbar, require logged-in.
			if ( 'adminbar' === $block || 'admin_bar' === $block || 'admin-bar' === $block ) {
				if ( ! is_user_logged_in() ) {
					self::emit_private_fail_closed();
					if ( function_exists( 'wp_send_json_error' ) ) {
						wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
					}
					return;
				}
			}

			// Allow per-block fragment generation.
			switch ( $block ) {
				case 'cart':
					$fragment = '<span>cart(3)</span>';
					break;
				case 'adminbar':
				case 'admin_bar':
				case 'admin-bar':
					$fragment = '<div class="wppo-adminbar">adminbar</div>';
					break;
				case 'nonce':
					// Fresh-nonce minting. Reachable without a valid presented
					// nonce (see the rate-limited refresh gate above) so stale
					// static-HTML cache pages can recover hydration.
					$fragment = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wppo_esi' ) : '';
					break;
				default:
					$fragment = '<div data-wppo-esi-fragment="' . esc_attr( $block ) . '"></div>';
					break;
			}

			/**
			 * Filter ESI fragment HTML.
			 *
			 * @since 2.0.0
			 * @param string $fragment Fragment HTML.
			 * @param string $block    Block name.
			 */
			$fragment = (string) apply_filters( 'wppo_esi_fragment_html', $fragment, $block );
			$fragment = (string) apply_filters( 'wppo_litespeed_esi_fragment_html', $fragment, $block );

			// Sanitization contract (see docblock): the fragment is inserted
			// into the DOM client-side (src/esi.js), so it must pass through
			// the wp_kses allowlist — including markup supplied via filters —
			// before it is echoed. Plain-text fragments (e.g. the nonce block)
			// pass through unchanged.
			$fragment = wp_kses(
				$fragment,
				self::get_allowed_fragment_html(),
				array( 'http', 'https', 'mailto', 'tel', 'relative' )
			);

			self::emit_private_fail_closed();

			if ( function_exists( 'wp_send_json_success' ) ) {
				wp_send_json_success( array( 'html' => $fragment ) );
			} else {
				echo wp_json_encode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					array(
						'success' => true,
						'data'    => array( 'html' => $fragment ),
					)
				);
				// Terminate like the wp_send_json_* path (which dies): extra
				// theme output or a trailing `0` would otherwise corrupt the
				// AJAX contract.
				if ( function_exists( 'wp_die' ) ) {
					wp_die();
				} else {
					die;
				}
			}
		}

		/**
		 * Inject nonce replacement into content.
		 *
		 * Replaces placeholder like __WPPO_ESI_NONCE__ or empty data-wppo-nonce with fresh nonce.
		 * Stores 12h transient blog-prefixed.
		 *
		 * Runs as a `wppo_esi_fragment_html` filter, so `$content` is whatever
		 * an earlier filter left behind. The parameter is deliberately untyped
		 * and non-strings are passed straight back: a filter returning null or
		 * an array must not turn fragment rendering into a TypeError fatal
		 * (fail-open, matching the rest of the ESI path). Only content that
		 * actually carries a `data-wppo-nonce` placeholder is rewritten.
		 *
		 * @since 2.0.0
		 * @since NEXT Untyped parameter and non-string passthrough, now that this
		 * runs as a live fragment filter.
		 * @param mixed $content Content to inject.
		 * @return mixed The rewritten content, or the input unchanged.
		 */
		public static function inject_nonce_replacement( $content ) {
			if ( ! is_string( $content ) || '' === $content || false === strpos( $content, 'data-wppo-nonce' ) ) {
				return $content;
			}

			// Fail closed when the nonce API is unavailable: a fallback md5
			// seed can never verify via wp_verify_nonce(), so injecting it
			// would mint placeholders that 403 forever.
			if ( ! function_exists( 'wp_create_nonce' ) || ! function_exists( 'wp_verify_nonce' ) ) {
				return $content;
			}
			$nonce = wp_create_nonce( 'wppo_esi' );
			// Fail closed per fallback_nonce_seed() contract: never inject
			// an empty nonce — leave the placeholder untouched so
			// verification (which rejects empty nonces) stays denied.
			if ( ! is_string( $nonce ) || '' === $nonce ) {
				return $content;
			}
			// Wildcard allowlist transient for ESI (wppo_-prefixed to avoid
			// collisions with other plugins on shared object-cache backends).
			// Per-request memo: this injector runs on every fragment
			// hydration, so once this request has confirmed (or written) the
			// wildcard, later hydrations skip the get+set entirely.
			$wildcard = Util::transient_key( 'wppo_esi_nonces' );
			if ( empty( self::$nonce_wildcard_memo[ $wildcard ] ) ) {
				$existing = get_transient( $wildcard );
				if ( ! is_array( $existing ) ) {
					$existing = array();
				}
				if ( ! in_array( 'wppo_esi', $existing, true ) ) {
					$existing[] = 'wppo_esi';
					set_transient( $wildcard, $existing, 12 * HOUR_IN_SECONDS );
				}
				self::$nonce_wildcard_memo[ $wildcard ] = true;
			}

			// Replace placeholder tokens.
			if ( false !== strpos( $content, '__WPPO_ESI_NONCE__' ) ) {
				$content = str_replace( '__WPPO_ESI_NONCE__', esc_attr( $nonce ), $content );
			}
			if ( false !== strpos( $content, '__WPPO_NONCE__' ) ) {
				$content = str_replace( '__WPPO_NONCE__', esc_attr( $nonce ), $content );
			}
			// Generic replace empty or placeholder data-wppo-nonce="".
			// Use regex to replace attribute value.
			$content = preg_replace_callback(
				'/data-wppo-nonce=(["\'])([^"\']*)\1/',
				static function ( $matches ) use ( $nonce ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
					return 'data-wppo-nonce="' . esc_attr( $nonce ) . '"';
				},
				$content
			);

			/**
			 * Filter nonce-replaced content.
			 *
			 * @since 2.0.0
			 * @param string $content Content after replacement.
			 * @param string $nonce   Nonce value.
			 */
			$content = (string) apply_filters( 'wppo_esi_nonce_content', $content, $nonce );
			$content = (string) apply_filters( 'wppo_litespeed_esi_nonce_content', $content, $nonce );

			return $content;
		}

		/**
		 * Handle send_headers — emit private/no-vary for cart/checkout/account, no-cache for admin.
		 *
		 * Hooked on send_headers:1.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function handle_send_headers(): void {
			if ( ! class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) || ! LiteSpeed_Integration::is_litespeed() ) {
				return;
			}
			// Non-LS early return — no headers.
			$headers_sent = function_exists( 'headers_sent' ) ? headers_sent() : false;
			if ( $headers_sent ) {
				return;
			}

			$is_cart     = false;
			$is_checkout = false;
			$is_account  = false;
			$is_admin    = false;

			if ( function_exists( 'is_cart' ) ) {
				try {
					$is_cart = is_cart();
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( function_exists( 'is_checkout' ) ) {
				try {
					$is_checkout = is_checkout();
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( function_exists( 'is_account_page' ) ) {
				try {
					$is_account = is_account_page();
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( function_exists( 'is_admin' ) ) {
				try {
					$is_admin = is_admin();
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// Cart / checkout / account → private,no-vary.
			if ( $is_cart || $is_checkout || $is_account ) {
				self::emit_private_fail_closed();
				/**
				 * Filter ESI private header decision.
				 *
				 * @since 2.0.0
				 * @param string $context Context.
				 */
				do_action( 'wppo_esi_private_headers_sent', 'private' );
				return;
			}

			// Admin → no-cache.
			if ( $is_admin ) {
				self::emit_nocache_fail_closed();
				do_action( 'wppo_esi_private_headers_sent', 'no-cache' );
				return;
			}

			// Generic-private fallback reusing the already-resolved
			// $is_cart/$is_checkout/$is_account above instead of re-calling
			// should_punch_hole() 4x (each call re-runs is_esi_available()
			// with has_filter + 2x apply_filters plus conditionals on the
			// send_headers hot path).
			$generic_private = $is_cart || $is_checkout || $is_account;
			if ( ! $generic_private && function_exists( 'is_user_logged_in' ) ) {
				try {
					$generic_private = is_user_logged_in();
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( ! $generic_private ) {
				$cart_cookie = isset( $_COOKIE['woocommerce_items_in_cart'] ) && is_string( $_COOKIE['woocommerce_items_in_cart'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['woocommerce_items_in_cart'] ) ) : '';
				$hash_cookie = isset( $_COOKIE['woocommerce_cart_hash'] ) && is_string( $_COOKIE['woocommerce_cart_hash'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['woocommerce_cart_hash'] ) ) : '';
				if ( '' !== $cart_cookie || '' !== $hash_cookie ) {
					$generic_private = true;
				}
			}
			if ( $generic_private ) {
				self::emit_private_fail_closed();
				do_action( 'wppo_esi_private_headers_sent', 'private' );
			}
		}

		/**
		 * Register ESI nonce/widget hooks.
		 *
		 * Should be called from Main::setup_hooks() when is_enabled() or always for OLS fallback.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function init(): void {
			// Always register AJAX handlers and send_headers when on LiteSpeed, even if not enabled,
			// because OLS fallback needs them. Early return only for non-LS.
			if ( ! class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) || ! LiteSpeed_Integration::is_litespeed() ) {
				return;
			}

			// Gate by effective_mode litespeed — non-LS early return already handled.
			if ( method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'effective_mode' ) ) {
				try {
					$mode = LiteSpeed_Integration::effective_mode();
					if ( 'litespeed' !== $mode && 'wppo' !== $mode ) {
						// Standalone → no ESI.
						if ( 'standalone' === $mode ) {
							return;
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// Register AJAX handlers regardless of enabled (needed for OLS hydration).
			self::register_ajax_handlers();

			// OLS placeholder mode (audit #898): enqueue the hydration client
			// whenever ESI placeholders can be emitted. The callback re-checks
			// setting + mode + ESI availability so Enterprise native ESI (which
			// hydrates server-side via <esi:include>) never loads the JS client.
			add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_hydration_client' ) );

			// Always register send_headers for private/no-vary.
			add_action( 'send_headers', array( self::class, 'handle_send_headers' ), 1 );

			// Only register ESI-specific hooks when the setting + Enterprise
			// availability gate (is_enabled()) passes. The previous
			// `!is_enabled() && !is_esi_available()` condition reduced to
			// `!available`, so on Enterprise the else branch ran even with
			// the setting disabled — queuing tags and emitting
			// X-LiteSpeed-Tag despite the toggle.
			if ( ! self::is_enabled() ) {
				// OLS fallback: no litespeed_nonce action needed, but the
				// nonce injector still runs on fragment output (below).
				add_filter( 'litespeed_esi_nonces', array( self::class, 'filter_esi_nonces' ) );
				self::register_nonce_injector();
				return;
			}

			add_action( 'litespeed_nonce', array( self::class, 'handle_nonce' ), 10, 1 );
			add_filter( 'litespeed_esi_nonces', array( self::class, 'filter_esi_nonces' ) );
			self::register_nonce_injector();
		}

		/**
		 * Attach the ESI nonce injector to the fragment-content filter.
		 *
		 * `inject_nonce_replacement()` rewrites `data-wppo-nonce` placeholders
		 * in fragment markup to a fresh nonce, so it belongs on the hook that
		 * actually carries fragment content: `wppo_esi_fragment_html`, applied
		 * in `handle_ajax_fragment()` before the `wp_kses` contract runs (the
		 * `data-*` allowlist there keeps the injected attribute). It runs
		 * *before* sanitization, which is why the injected value must survive
		 * `get_allowed_fragment_html()`.
		 *
		 * It previously sat on `wppo_esi_nonce` / `wppo_litespeed_esi_nonce`,
		 * which nothing ever applies — the registration was dead, so the
		 * injector never ran (issue #1084 item 4). Those names must not be
		 * reused for this method: it *applies* `wppo_esi_nonce_content` and
		 * `wppo_litespeed_esi_nonce_content` internally, so registering it on
		 * those would recurse.
		 *
		 * Registered in both the enabled and the OLS-fallback branch, because
		 * the fragment endpoint serves the hydration client either way.
		 *
		 * @since NEXT
		 * @return void
		 */
		private static function register_nonce_injector(): void {
			add_filter( 'wppo_esi_fragment_html', array( self::class, 'inject_nonce_replacement' ), 10, 1 );
			add_filter( 'wppo_litespeed_esi_fragment_html', array( self::class, 'inject_nonce_replacement' ), 10, 1 );
		}

		/**
		 * Sanitize an ESI tag value through the canonical sanitizer when
		 * available, with a local fallback otherwise.
		 *
		 * Single home for the emitter-unavailable path so handle_nonce()
		 * no longer hand-mirrors Header_Emitter::sanitize_tag() inline: when
		 * the emitter is loaded the canonical implementation is reused
		 * directly, and the inline fallback below matches its contract
		 * (strip ASCII controls incl. CR/LF/NUL, cap at 1024 chars),
		 * failing closed to an empty tag on regex failure.
		 *
		 * @since NEXT
		 * @param string $action Raw ESI action name.
		 * @return string Sanitized tag value (max 1024 chars).
		 */
		private static function sanitize_esi_tag_value( string $action ): string {
			if ( self::has_header_emitter() ) {
				return Header_Emitter::sanitize_tag( $action );
			}
			$cleaned = preg_replace( '/[\x00-\x1F\x7F]/', '', $action );
			if ( ! is_string( $cleaned ) ) {
				return '';
			}
			return substr( $cleaned, 0, 1024 );
		}

		/**
		 * Handle litespeed_nonce action for widget/cart hole-punching.
		 *
		 * @since 2.0.0
		 * @param string $action Nonce action.
		 * @return void
		 */
		public static function handle_nonce( $action ): void {
			if ( ! self::is_setting_enabled() ) {
				return;
			}
			if ( ! self::is_esi_available() ) {
				return;
			}
			$action = sanitize_text_field( (string) $action );
			if ( '' === $action ) {
				return;
			}
			// Tag ESI nonce for purge: ESI. + W.{id} pattern. The purge queue
			// above is authoritative; the response tag header below is
			// best-effort observability, mirrored via direct header() when the
			// emitter is unavailable (fail-closed, like the privacy paths).
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) ) {
				LiteSpeed_Integration::queue_purge_tags( array( 'ESI.' . $action, 'W.' . md5( $action ) ), 'private' );
			}
			if ( self::has_header_emitter() ) {
				Header_Emitter::emit_esi_tag( $action );
			} elseif ( function_exists( 'headers_sent' ) && ! headers_sent() ) {
				$safe = self::sanitize_esi_tag_value( $action );
				header( 'X-LiteSpeed-Tag: ESI.' . $safe, false );
			}
		}

		/**
		 * Filter litespeed_esi_nonces for hole-punch list.
		 *
		 * @since 2.0.0
		 * @param array $nonces Nonce list.
		 * @return array
		 */
		public static function filter_esi_nonces( $nonces ): array {
			if ( ! is_array( $nonces ) ) {
				$nonces = array();
			}
			// Add wildcard and wppo_esi nonces.
			if ( ! in_array( 'wppo_esi', $nonces, true ) ) {
				$nonces[] = 'wppo_esi';
			}
			if ( ! in_array( 'wppo_esi_nonce', $nonces, true ) ) {
				$nonces[] = 'wppo_esi_nonce';
			}
			/**
			 * Filter ESI nonces for widget/cart.
			 *
			 * @since 2.0.0
			 * @param array $nonces Nonce list.
			 */
			$nonces = (array) apply_filters( 'wppo_esi_nonces', $nonces );
			$nonces = (array) apply_filters( 'wppo_litespeed_esi_nonces', $nonces );
			return array_values( array_unique( $nonces ) );
		}

		/**
		 * AJAX fallback fragment for OLS (no ESI).
		 *
		 * Emits DONOTCACHEPAGE guard and returns fragment via wp-ajax.
		 * Filterable via wppo_esi_fallback.
		 *
		 * @since 2.0.0
		 * @param string $fragment Fragment HTML.
		 * @return string
		 */
		public static function ajax_fallback( string $fragment ): string {
			/**
			 * Filter whether ESI fallback should use AJAX.
			 *
			 * @since 2.0.0
			 * @param bool   $use_fallback Whether to use fallback.
			 * @param string $fragment Fragment HTML.
			 */
			$use_fallback = (bool) apply_filters( 'wppo_esi_fallback', true, $fragment );
			if ( ! $use_fallback ) {
				return $fragment;
			}
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			// The constant alone is read too late by some stacks — also send
			// no-cache headers now (guarded: never emit after headers sent).
			$headers_sent = function_exists( 'headers_sent' ) ? headers_sent() : true;
			if ( ! $headers_sent ) {
				if ( function_exists( 'nocache_headers' ) ) {
					nocache_headers();
				} else {
					self::emit_nocache_fail_closed();
				}
			}
			return $fragment;
		}
	}
}
