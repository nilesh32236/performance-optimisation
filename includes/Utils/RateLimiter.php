<?php
/**
 * Rate Limiter Utility
 *
 * @package PerformanceOptimisation\Utils
 */

namespace PerformanceOptimisation\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RateLimiter {

	/**
	 * Check if request is rate limited
	 *
	 * @param string $key Unique key for the rate limit
	 * @param int    $max_requests Maximum requests allowed
	 * @param int    $period Time period in seconds
	 * @return bool True if rate limited, false otherwise
	 */
	public static function is_limited( string $key, int $max_requests = 10, int $period = 60 ): bool {
		$transient_key = 'wppo_rate_limit_' . md5( $key );
		$requests      = get_transient( $transient_key );

		if ( false === $requests ) {
			set_transient( $transient_key, 1, $period );
			return false;
		}

		if ( $requests >= $max_requests ) {
			return true;
		}

		set_transient( $transient_key, $requests + 1, $period );
		return false;
	}

	/**
	 * Get rate limit key for current user/IP
	 *
	 * @param string $action Action being rate limited
	 * @return string
	 */
	public static function get_key( string $action ): string {
		$user_id = get_current_user_id();
		$ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		return $action . '_' . $user_id . '_' . $ip;
	}
}
