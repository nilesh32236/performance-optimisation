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
			 * Filter whether ESI is available.
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
		 * Register ESI nonce/widget hooks.
		 *
		 * Should be called from Main::setup_hooks() when is_enabled().
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function init(): void {
			if ( ! self::is_enabled() ) {
				return;
			}
			add_action( 'litespeed_nonce', array( self::class, 'handle_nonce' ), 10, 1 );
			add_filter( 'litespeed_esi_nonces', array( self::class, 'filter_esi_nonces' ) );
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
			/**
			 * Filter ESI nonces for widget/cart.
			 *
			 * @since NEXT
			 * @param array $nonces Nonce list.
			 */
			$nonces = (array) apply_filters( 'wppo_esi_nonces', $nonces );
			return $nonces;
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
