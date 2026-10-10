<?php
/**
 * Apo_Detect boundary — Cloudflare APO double-cache detection.
 *
 * When Cloudflare Automatic Platform Optimization (APO) already caches HTML
 * at the edge, writing a second WPPO static HTML layer double-caches the
 * page and causes stale-HTML conflicts. Detection is constant + option
 * based and filter-overridable, and fails closed to inactive (no degrade)
 * so unknown environments never lose caching. Request headers are never
 * trusted: they are client-controllable and must not disable caching.
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
		 * Per-request memo of the APO verdict (null = not yet probed).
		 *
		 * The is_apo_active() method runs on the hottest frontend path
		 * (Cache::is_not_cacheable) and hits filters/options on every
		 * call; memoize so one request probes once. Reset via
		 * reset_cache() (unit tests, long-running workers).
		 *
		 * @since NEXT
		 * @var bool|null
		 */
		private static $active_memo = null;

		/**
		 * Whether Cloudflare APO HTML caching is active.
		 *
		 * Probes (in order): the `wppo_cf_apo_active` filter override, the
		 * `CLOUDFLARE_APO_ENABLED` constant, and the official Cloudflare
		 * plugin APO option. Request headers (`CF-APO-VIA`,
		 * `CF-Edge-Cache`) are deliberately NOT trusted: they are
		 * client-controllable, so any visitor could otherwise force an
		 * APO degrade and disable the WPPO static cache. Never throws;
		 * fails closed to false. Per-request memoized.
		 *
		 * @since NEXT
		 * @return bool True when APO is active and WPPO must not double-cache.
		 */
		public static function is_apo_active(): bool {
			if ( null !== self::$active_memo ) {
				return self::$active_memo;
			}

			$active = false;
			try {
				if ( function_exists( 'apply_filters' ) ) {
					$override = apply_filters( self::FILTER_ACTIVE, null );
					if ( null !== $override ) {
						self::$active_memo = (bool) $override;
						return self::$active_memo;
					}
				}

				if ( defined( 'CLOUDFLARE_APO_ENABLED' ) && constant( 'CLOUDFLARE_APO_ENABLED' ) ) {
					$active = true;
				} elseif ( function_exists( 'get_option' ) ) {
					$cf = get_option( 'cloudflare', null );
					if ( is_array( $cf ) && ! empty( $cf['automatic_platform_optimization'] ) ) {
						$active = true;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$active = false;
			}

			self::$active_memo = $active;
			return $active;
		}

		/**
		 * Clear the per-request APO verdict memo.
		 *
		 * Intended for unit tests and long-running workers where the
		 * filter, constant state, or options change mid-process.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_cache(): void {
			self::$active_memo = null;
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
