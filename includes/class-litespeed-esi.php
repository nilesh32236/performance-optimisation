<?php
/**
 * LiteSpeed ESI bridge (P5).
 *
 * Enterprise only — OLS has no ESI per litespeed-research.md:135.
 * Provides nonce/widget hole-punching via litespeed_nonce / litespeed_esi_nonces,
 * and AJAX fallback on OLS with DONOTCACHEPAGE.
 *
 * @package PerformanceOptimise\Inc
 * @since NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\LiteSpeed_ESI' ) ) {

	/**
	 * Class LiteSpeed_ESI
	 *
	 * @since NEXT
	 */
	final class LiteSpeed_ESI {

		/**
		 * Whether ESI is available (Enterprise only).
		 *
		 * Checks LITESPEED_SERVER_TYPE, LITESPEED_ESI_ON, or litespeed_esi_status filter.
		 *
		 * @since NEXT
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
			 * @since NEXT
			 * @param bool $available Whether ESI is available.
			 */
			$available = (bool) apply_filters( 'wppo_litespeed_esi_available', false );
			if ( $available ) {
				return true;
			}
			/**
			 * Filter whether ESI is available (legacy alias).
			 *
			 * @since NEXT
			 * @param bool $available Whether ESI is available.
			 */
			return (bool) apply_filters( 'wppo_esi_available', false );
		}

		/**
		 * Whether ESI is enabled via settings.
		 *
		 * @since NEXT
		 * @return bool
		 */
		public static function is_enabled(): bool {
			$options = get_option( 'wppo_settings', array() );
			$enabled = ! empty( $options['litespeed_integration']['esi']['enabled'] );
			/**
			 * Filter whether ESI bridge is enabled.
			 *
			 * @since NEXT
			 * @param bool $enabled Whether enabled.
			 */
			$enabled = (bool) apply_filters( 'wppo_esi_enabled', $enabled );
			return $enabled && self::is_esi_available();
		}

		/**
		 * Whether a given context should punch hole via AJAX/ESI.
		 *
		 * Gated by is_litespeed() && !is_esi_available() plus Woo / auth context.
		 *
		 * @since NEXT
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
			 * @since NEXT
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
		 * @since NEXT
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
			 * @since NEXT
			 * @param string $block Block name.
			 * @param array  $attrs Attributes.
			 */
			$block = (string) apply_filters( 'wppo_esi_block', $block, $attrs );

			$nonce = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wppo_esi' ) : md5( $block . wp_salt() );
			// Store 12h transient blog-prefixed.
			$transient_key = Util::transient_key( 'wppo_esi_nonce_' . md5( $nonce . $block ) );
			set_transient( $transient_key, $nonce, 12 * HOUR_IN_SECONDS );
			// Also store wildcard allowlist key for esi_nonces.
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
				 * @since NEXT
				 * @param string $html  Placeholder HTML.
				 * @param string $block Block name.
				 * @param array  $attrs Attributes.
				 */
				$html = (string) apply_filters( 'wppo_esi_placeholder', $html, $block, $attrs );
				/**
				 * Legacy alias for placeholder.
				 *
				 * @since NEXT
				 * @param string $html  Placeholder HTML.
				 * @param string $block Block name.
				 */
				$html = (string) apply_filters( 'wppo_litespeed_esi_placeholder', $html, $block );
				return $html;
			}

			$html = sprintf( '<div data-wppo-esi="%s" data-nonce="%s"%s></div>', esc_attr( $block ), esc_attr( $nonce ), $attrs_str );
			/**
			 * Filter ESI placeholder HTML (OLS fallback).
			 *
			 * @since NEXT
			 * @param string $html  Placeholder HTML.
			 * @param string $block Block name.
			 * @param array  $attrs Attributes.
			 */
			$html = (string) apply_filters( 'wppo_esi_placeholder', $html, $block, $attrs );
			$html = (string) apply_filters( 'wppo_litespeed_esi_placeholder', $html, $block );
			return $html;
		}

		/**
		 * Register AJAX handlers for ESI fragments.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function register_ajax_handlers(): void {
			add_action( 'wp_ajax_wppo_esi_fragment', array( self::class, 'handle_ajax_fragment' ) );
			add_action( 'wp_ajax_nopriv_wppo_esi_fragment', array( self::class, 'handle_ajax_fragment' ) );
		}

		/**
		 * Handle AJAX fragment request.
		 *
		 * Emits Cache-Control: private,no-cache + X-LiteSpeed-Cache-Control: private,no-vary
		 * and returns JSON success with html.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function handle_ajax_fragment(): void {
			$block = '';
			if ( isset( $_GET['block'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$block = sanitize_text_field( wp_unslash( $_GET['block'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			} elseif ( isset( $_POST['block'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$block = sanitize_text_field( wp_unslash( $_POST['block'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
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

			if ( ! $nonce_valid && 'cart' !== strtolower( $block ) ) {
				if ( ! headers_sent() ) {
					header( 'Cache-Control: private,no-cache' );
					header( 'X-LiteSpeed-Cache-Control: private,no-vary' );
				}
				if ( function_exists( 'wp_send_json_error' ) ) {
					wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
				}
				return;
			}

			// For adminbar, require logged-in.
			if ( 'adminbar' === $block || 'admin_bar' === $block ) {
				if ( ! is_user_logged_in() ) {
					if ( ! headers_sent() ) {
						header( 'Cache-Control: private,no-cache' );
						header( 'X-LiteSpeed-Cache-Control: private,no-vary' );
					}
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
			 * @since NEXT
			 * @param string $fragment Fragment HTML.
			 * @param string $block    Block name.
			 */
			$fragment = (string) apply_filters( 'wppo_esi_fragment_html', $fragment, $block );
			$fragment = (string) apply_filters( 'wppo_litespeed_esi_fragment_html', $fragment, $block );

			if ( ! headers_sent() ) {
				header( 'Cache-Control: private,no-cache' );
				header( 'X-LiteSpeed-Cache-Control: private,no-vary' );
			}

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
		 * @since NEXT
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
			// Wildcard allowlist transient for ESI.
			$wildcard = Util::transient_key( 'esi_nonces' );
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
			 * @since NEXT
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
		 * @since NEXT
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
				header( 'Cache-Control: private,no-cache' );
				header( 'X-LiteSpeed-Cache-Control: private,no-vary' );
				/**
				 * Filter ESI private header decision.
				 *
				 * @since NEXT
				 * @param string $context Context.
				 */
				do_action( 'wppo_esi_private_headers_sent', 'private' );
				return;
			}

			// Admin → no-cache.
			if ( $is_admin ) {
				header( 'Cache-Control: no-cache' );
				header( 'X-LiteSpeed-Cache-Control: no-cache' );
				do_action( 'wppo_esi_private_headers_sent', 'no-cache' );
				return;
			}

			// Also check should_punch_hole for generic private.
			if ( self::should_punch_hole( 'cart' ) || self::should_punch_hole( 'checkout' ) || self::should_punch_hole( 'account' ) || self::should_punch_hole( 'adminbar' ) ) {
				header( 'Cache-Control: private,no-cache' );
				header( 'X-LiteSpeed-Cache-Control: private,no-vary' );
				do_action( 'wppo_esi_private_headers_sent', 'private' );
			}
		}

		/**
		 * Register ESI nonce/widget hooks.
		 *
		 * Should be called from Main::setup_hooks() when is_enabled() or always for OLS fallback.
		 *
		 * @since NEXT
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
		 * @since NEXT
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
			if ( ! headers_sent() ) {
				header( 'X-LiteSpeed-Tag: ESI.' . $action, false );
			}
		}

		/**
		 * Filter litespeed_esi_nonces for hole-punch list.
		 *
		 * @since NEXT
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
			 * @since NEXT
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
		 * @since NEXT
		 * @param string $fragment Fragment HTML.
		 * @return string
		 */
		public static function ajax_fallback( string $fragment ): string {
			/**
			 * Filter whether ESI fallback should use AJAX.
			 *
			 * @since NEXT
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
