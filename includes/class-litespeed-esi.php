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
		 * supplies the version + dependency list, with a classic footer enqueue —
		 * this client is plain vanilla JS, not a script module.
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
			$deps       = array();
			$version    = WPPO_VERSION;
			if ( file_exists( $asset_file ) ) {
				$asset   = include $asset_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
				$deps    = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
				$version = isset( $asset['version'] ) ? $asset['version'] : WPPO_VERSION;
			}

			wp_enqueue_script( 'wppo-esi', WPPO_PLUGIN_URL . 'build/esi.js', $deps, $version, array( 'in_footer' => true ) );
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
					if ( ! empty( $_COOKIE['woocommerce_items_in_cart'] ) || ! empty( $_COOKIE['woocommerce_cart_hash'] ) ) {
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

			$nonce = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wppo_esi' ) : md5( $block . wp_salt() );
			// Store 12h transient blog-prefixed.
			$transient_key = Util::transient_key( 'wppo_esi_nonce_' . md5( $nonce . $block ) );
			set_transient( $transient_key, $nonce, 12 * HOUR_IN_SECONDS );
			// Also store wildcard allowlist key for wppo_esi_nonces.
			$wildcard_key = Util::transient_key( 'wppo_esi_nonce_' . $block );
			set_transient( $wildcard_key, 1, 12 * HOUR_IN_SECONDS );

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
			return (array) apply_filters( 'wppo_esi_allowed_html', $tags );
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
		 * @since 2.0.0
		 * @return void
		 */
		public static function handle_ajax_fragment(): void {
			$block = '';
			if ( isset( $_GET['block'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$block = strtolower( sanitize_text_field( wp_unslash( $_GET['block'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			} elseif ( isset( $_POST['block'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$block = strtolower( sanitize_text_field( wp_unslash( $_POST['block'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}
			if ( '' === $block ) {
				$block = 'cart';
			}

			// Require valid 'wppo_esi' nonce for all blocks except public cart.
			$nonce = '';
			if ( isset( $_GET['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			} elseif ( isset( $_POST['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
			$nonce_valid = function_exists( 'wp_verify_nonce' ) ? wp_verify_nonce( $nonce, 'wppo_esi' ) : false;

			if ( ! $nonce_valid && 'cart' !== $block && 'nonce' !== $block ) {
				Header_Emitter::emit_private_pair();
				if ( function_exists( 'wp_send_json_error' ) ) {
					wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
				}
				return;
			}

			// For adminbar, require logged-in.
			if ( 'adminbar' === $block || 'admin_bar' === $block || 'admin-bar' === $block ) {
				if ( ! is_user_logged_in() ) {
					Header_Emitter::emit_private_pair();
					if ( function_exists( 'wp_send_json_error' ) ) {
						wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
					}
					return;
				}
			}

			$fragment = '<span>cart(3)</span>';
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

			Header_Emitter::emit_private_pair();

			if ( function_exists( 'wp_send_json_success' ) ) {
				wp_send_json_success( array( 'html' => $fragment ) );
			} else {
				echo wp_json_encode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					array(
						'success' => true,
						'data'    => array( 'html' => $fragment ),
					)
				);
			}
		}

		/**
		 * Inject nonce replacement into content.
		 *
		 * Replaces placeholder like __WPPO_ESI_NONCE__ or empty data-wppo-nonce with fresh nonce.
		 * Stores 12h transient blog-prefixed.
		 *
		 * @since 2.0.0
		 * @param string $content Content to inject.
		 * @return string
		 */
		public static function inject_nonce_replacement( string $content ): string {
			if ( '' === $content || false === strpos( $content, 'data-wppo-nonce' ) ) {
				return $content;
			}

			$nonce = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wppo_esi' ) : md5( wp_salt() . 'wppo_esi' );
			$key   = Util::transient_key( 'wppo_esi_nonce_' . md5( $nonce ) );
			set_transient( $key, $nonce, 12 * HOUR_IN_SECONDS );
			// Wildcard allowlist transient for ESI (wppo_-prefixed to avoid
			// collisions with other plugins on shared object-cache backends).
			$wildcard = Util::transient_key( 'wppo_esi_nonces' );
			$existing = get_transient( $wildcard );
			if ( ! is_array( $existing ) ) {
				$existing = array();
			}
			if ( ! in_array( 'wppo_esi', $existing, true ) ) {
				$existing[] = 'wppo_esi';
				set_transient( $wildcard, $existing, 12 * HOUR_IN_SECONDS );
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
				Header_Emitter::emit_private_pair();
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
				Header_Emitter::emit_nocache_pair();
				do_action( 'wppo_esi_private_headers_sent', 'no-cache' );
				return;
			}

			// Also check should_punch_hole for generic private.
			if ( self::should_punch_hole( 'cart' ) || self::should_punch_hole( 'checkout' ) || self::should_punch_hole( 'account' ) || self::should_punch_hole( 'adminbar' ) ) {
				Header_Emitter::emit_private_pair();
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

			// Only register ESI-specific hooks when enabled and available.
			if ( ! self::is_enabled() && ! self::is_esi_available() ) {
				// Still register generic ESI nonces filter for OLS? Allow.
				// For OLS fallback we don't need litespeed_nonce, but keep nonce replacement filter.
				add_filter( 'wppo_esi_nonce', array( self::class, 'inject_nonce_replacement' ), 10, 1 );
				add_filter( 'litespeed_esi_nonces', array( self::class, 'filter_esi_nonces' ) );
				return;
			}

			add_action( 'litespeed_nonce', array( self::class, 'handle_nonce' ), 10, 1 );
			add_filter( 'litespeed_esi_nonces', array( self::class, 'filter_esi_nonces' ) );
			add_filter( 'wppo_esi_nonce', array( self::class, 'inject_nonce_replacement' ), 10, 1 );
			// Also filter content for nonce replacement on litespeed_nonce action.
			add_filter( 'wppo_litespeed_esi_nonce', array( self::class, 'inject_nonce_replacement' ), 10, 1 );
		}

		/**
		 * Handle litespeed_nonce action for widget/cart hole-punching.
		 *
		 * @since 2.0.0
		 * @param string $action Nonce action.
		 * @return void
		 */
		public static function handle_nonce( $action ): void {
			if ( ! self::is_esi_available() ) {
				return;
			}
			$action = sanitize_text_field( (string) $action );
			if ( '' === $action ) {
				return;
			}
			// Tag ESI nonce for purge: ESI. + W.{id} pattern.
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) ) {
				LiteSpeed_Integration::queue_purge_tags( array( 'ESI.' . $action, 'W.' . md5( $action ) ), 'private' );
			}
			Header_Emitter::emit_esi_tag( $action );
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
			return $fragment;
		}
	}
}
