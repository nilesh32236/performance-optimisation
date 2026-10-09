<?php
/**
 * Apo_Detect boundary — Cloudflare APO double-cache detection.
 *
 * When Cloudflare Automatic Platform Optimization (APO) already caches HTML
 * at the edge, writing a second WPPO static HTML layer double-caches the
 * page and causes stale-HTML conflicts. Detection is header + constant +
 * option based, filter-overridable, and fails closed to inactive (no
 * degrade) so unknown environments never lose caching.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Apo_Detect' ) ) {
	/**
	 * Cloudflare APO detection boundary.
	 *
	 * @since NEXT
	 */
	final class Apo_Detect {

		/**
		 * Filter overriding APO detection.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const FILTER_ACTIVE = 'wppo_cf_apo_active';

		/**
		 * Whether Cloudflare APO HTML caching is active.
		 *
		 * Probes (in order): the `wppo_cf_apo_active` filter override, APO
		 * response/request headers, the `CLOUDFLARE_APO_ENABLED` constant,
		 * and the official Cloudflare plugin APO option. Never throws;
		 * fails closed to false.
		 *
		 * @since NEXT
		 * @return bool True when APO is active and WPPO must not double-cache.
		 */
		public static function is_apo_active(): bool {
			try {
				if ( function_exists( 'apply_filters' ) ) {
					$override = apply_filters( self::FILTER_ACTIVE, null );
					if ( null !== $override ) {
						return (bool) $override;
					}
				}

				$apo_via = isset( $_SERVER['HTTP_CF_APO_VIA'] ) && is_string( $_SERVER['HTTP_CF_APO_VIA'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_APO_VIA'] ) ) : '';
				if ( '' !== $apo_via ) {
					return true;
				}
				$edge_cache = isset( $_SERVER['HTTP_CF_EDGE_CACHE'] ) && is_string( $_SERVER['HTTP_CF_EDGE_CACHE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_EDGE_CACHE'] ) ) : '';
				if ( '' !== $edge_cache && false !== stripos( $edge_cache, 'apo' ) ) {
					return true;
				}

				if ( defined( 'CLOUDFLARE_APO_ENABLED' ) && constant( 'CLOUDFLARE_APO_ENABLED' ) ) {
					return true;
				}

				if ( function_exists( 'get_option' ) ) {
					$cf = get_option( 'cloudflare', null );
					if ( is_array( $cf ) && ! empty( $cf['automatic_platform_optimization'] ) ) {
						return true;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			return false;
		}

		/**
		 * Human-readable degrade reason, or '' when APO is inactive.
		 *
		 * @since NEXT
		 * @return string Reason string for notices / System Info.
		 */
		public static function get_degrade_reason(): string {
			if ( ! self::is_apo_active() ) {
				return '';
			}
			return __( 'Cloudflare APO already caches HTML at the edge; WPPO static cache is disabled to avoid double-caching. Purges route through Cloudflare.', 'performance-optimisation' );
		}
	}
}
