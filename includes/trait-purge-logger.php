<?php
/**
 * Shared purge-failure logger for CDN/Edge/Cloudflare purgers.
 *
 * Extracts the triplicated `log_failure()` implementations (which had
 * diverging throttle behavior) into one trait so a retry/auth fix in one
 * purger reaches the others.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! trait_exists( 'PerformanceOptimise\Inc\Purge_Logger' ) ) {
	/**
	 * Shared throttled purge-failure logging.
	 *
	 * @since NEXT
	 */
	trait Purge_Logger {

		/**
		 * Surface a failed purge via the debug log + throttled activity log.
		 *
		 * Always fires `wppo_debug_log`. When no listener is attached, mirrors
		 * to the activity log throttled to one row per service per lock window
		 * so a prolonged outage cannot spam `wppo_activity_logs`. Fail-open:
		 * logging never breaks the purge path.
		 *
		 * @since NEXT
		 * @param string $service        Service tag (e.g. 'cloudflare', 'cloudflare-edge', 'bunny-edge').
		 * @param string $detail         Endpoint / reason (truncated to 200 chars in the activity log).
		 * @param string $log_prefix     Debug-log prefix (default 'Edge purge failed').
		 * @param string $throttle_group Throttle namespace (default 'wppo_edge_purge_log_lock').
		 * @param int    $throttle_ttl   Throttle window in seconds (default 60).
		 * @return void
		 */
		private static function log_purge_failure( string $service, string $detail, string $log_prefix = 'Edge purge failed', string $throttle_group = 'wppo_edge_purge_log_lock', int $throttle_ttl = 60 ): void {
			do_action( 'wppo_debug_log', $log_prefix . ' [' . $service . ']: ' . $detail );
			try {
				if ( ! has_filter( 'wppo_debug_log' ) && class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
					$service_slug = strtolower( (string) preg_replace( '/[^a-z0-9]+/', '-', (string) $service ) );
					$service_slug = trim( substr( $service_slug, 0, 32 ), '-' );
					if ( '' === $service_slug ) {
						$service_slug = 'edge';
					}
					$throttle_key = Util::transient_key( $throttle_group . '_' . $service_slug );
					if ( false === get_transient( $throttle_key ) ) {
						set_transient( $throttle_key, 1, $throttle_ttl > 0 ? $throttle_ttl : 60 );
						Log::add( $log_prefix . ' [' . $service . ']: ' . substr( $detail, 0, 200 ) );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}
}
