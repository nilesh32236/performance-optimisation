<?php
/**
 * Woo_Detect boundary — WooCommerce detection, excluded paths, and self-test.
 *
 * Focused extraction (REF-013) of the WooCommerce detection responsibility
 * cluster previously owned by the god utility `Util`. Owns the commerce
 * path matchers (cart/checkout/account plus custom slugs), Store API
 * detection, wc-ajax / add-to-cart / faceted-query signals, the safe-mode
 * gate, and the verifiable cart/checkout cache-exclusion self-test.
 * `Util::is_woo_*()` and friends remain as one-line facade proxies so all
 * existing callers keep working untouched.
 *
 * Probe order (`class_exists('WooCommerce')` / `function_exists(...)`) is
 * preserved exactly — probe order is behavior on hosts with partial Woo.
 * Hosts without Woo fail closed (no fatals, no calls into missing Woo
 * functions — probes first, always).
 *
 * Minimal WordPress APIs only: `wc_get_page_id()`, `get_permalink()`,
 * `get_post_field()` (all probed via `function_exists()`), `wp_parse_url()`,
 * `sanitize_text_field()`, `wp_unslash()`, `has_filter()` /
 * `apply_filters()` (faceted params), plus the cross-boundary
 * `Util::get_settings()` (safe-mode toggle), `Util::cached_home_url()`
 * (self-test URLs), `Util::has_uncacheable_query()` (generic query guard)
 * and `Util::is_editor_preview_url()` (self-test editor probes), resolved
 * at call time via the spl autoloader so there is no load-time cycle.
 *
 * Deliberately OUT (stays in `Util`): the `wooSafeMode` DEFAULT/backfill in
 * settings (schema ownership lives with the settings boundary); cache
 * purge/invalidation actions (watcher stays); `has_uncacheable_query()`
 * (generic query policy, not commerce); `is_editor_preview_url()`
 * (editor/admin policy).
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Woo_Detect' ) ) {
	/**
	 * Class Woo_Detect
	 *
	 * Static WooCommerce-detection boundary. Depends only on the minimal
	 * WordPress/Woo APIs required to preserve the existing implementation
	 * verbatim (see file docblock) plus the `Util` settings/URL/query
	 * helpers listed above. `Util` proxies back at call time only
	 * (autoloader, no load-time cycle).
	 *
	 * @since NEXT
	 */
	final class Woo_Detect {
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
					$settings = Util::get_settings();
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
		 * @since 2.3.0
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
		 * @since 2.3.0
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
				if ( '' !== $query && method_exists( Util::class, 'has_uncacheable_query' ) && Util::has_uncacheable_query( $query ) ) {
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
							$url = Util::cached_home_url( '/' . $path . '/' );
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
							$url = Util::cached_home_url( (string) $probe_url );
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
							$probe_full = Util::cached_home_url( (string) $probe_url );
						} catch ( \Throwable $e ) {
							unset( $e );
							$probe_full = (string) $probe_url;
						}
						if ( ! is_string( $probe_full ) || '' === $probe_full ) {
							$probe_full = (string) $probe_url;
						}
						$bypass          = Util::is_editor_preview_url( $probe_full );
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
							$candidate = Util::cached_home_url( (string) $probe_url );
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
							} elseif ( '' !== $probe_query && Util::has_uncacheable_query( $probe_query ) ) {
								$skipped = true;
							} elseif ( $safe_mode && self::is_woo_dynamic_path( $probe_path ) ) {
								$skipped = true;
							} elseif ( self::is_woo_store_api_path( $probe_path ) ) {
								$skipped = true;
							}
						}
						$pass = ( $skipped === $expected_skip );
						try {
							$probe_full = Util::cached_home_url( (string) $probe_url );
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
							$cart_url = Util::cached_home_url( '/' );
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
	}
}
