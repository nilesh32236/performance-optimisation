<?php
/**
 * Critical CSS generation lifecycle policy.
 *
 * CSS domain: owns the bounded generation status, retry, timeout, and queue
 * liveness policy. Critical_CSS keeps the public generation and frontend
 * facades; Ccss_Store remains the file/status projection owner.
 *
 * @package PerformanceOptimise\Inc
 * @since NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Ccss_Generator' ) ) {
	/**
	 * Critical CSS generation lifecycle policy.
	 *
	 * This owner deliberately does not fetch, parse, inline, or delete CSS.
	 * It owns the status values, transient/cache key policy, bounded retry
	 * counters, timeout escalation, and Action Scheduler/WP-Cron job liveness
	 * used by the generation paths. All methods fail open exactly as the
	 * historical Critical_CSS implementations did.
	 *
	 * @since NEXT
	 */
	final class Ccss_Generator {
		/** Status-cache salt option key.
		 *
		 * @since NEXT
		 * @var string
		 */
		private const SALT_KEY = 'wppo_ccss_salt';

		/** Dedicated Action Scheduler group for Critical CSS jobs.
		 *
		 * @since NEXT
		 * @var string
		 */
		private const CCSS_AS_GROUP = 'wppo-ccss';

		/** Default bounded generic retry cap.
		 *
		 * @since NEXT
		 * @var int
		 */
		private const DEFAULT_CCSS_MAX_RETRIES = 5;

		/** Maximum bounded generic retry cap.
		 *
		 * @since NEXT
		 * @var int
		 */
		private const MAX_CCSS_MAX_RETRIES = 5;

		/** Maximum consecutive timeout attempts.
		 *
		 * @since NEXT
		 * @var int
		 */
		private const MAX_CCSS_TIMEOUT_ATTEMPTS = 5;

		/** Per-request pending/running job memo keyed by hook and arguments.
		 *
		 * @since NEXT
		 * @var array<string,bool>
		 */
		private static array $pending_memo = array();

		/**
		 * Read the bounded generic retry cap.
		 *
		 * @return int Retry cap, 0..5.
		 * @since NEXT
		 */
		public static function get_ccss_max_retries(): int {
			try {
				$options = Util::get_settings();
				$raw     = $options['file_optimisation']['ccssMaxRetries'] ?? self::DEFAULT_CCSS_MAX_RETRIES;
				$cap     = is_numeric( $raw ) ? (int) $raw : self::DEFAULT_CCSS_MAX_RETRIES;
				$cap     = min( self::MAX_CCSS_MAX_RETRIES, max( 0, $cap ) );
				if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wppo_ccss_max_retries' ) ) {
					$filtered = apply_filters( 'wppo_ccss_max_retries', $cap );
					if ( is_numeric( $filtered ) ) {
						$cap = min( self::MAX_CCSS_MAX_RETRIES, max( 0, (int) $filtered ) );
					}
				}
				return $cap;
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::DEFAULT_CCSS_MAX_RETRIES;
			}
		}

		/**
		 * Read consecutive generic failures for a template.
		 *
		 * @param string $template_hash Template hash.
		 * @return int Failure count.
		 * @since NEXT
		 */
		public static function get_generation_attempts( string $template_hash ): int {
			try {
				if ( ! function_exists( 'get_transient' ) || ! Ccss_Store::is_valid_template_hash( $template_hash ) ) {
					return 0;
				}
				$stored = get_transient( Util::transient_key( 'wppo_ccss_attempts_' . $template_hash ) );
				return is_numeric( $stored ) ? max( 0, (int) $stored ) : 0;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Clear consecutive generic failures.
		 *
		 * @param string $template_hash Template hash.
		 * @return void
		 * @since NEXT
		 */
		public static function clear_generation_attempts( string $template_hash ): void {
			try {
				if ( function_exists( 'delete_transient' ) && Ccss_Store::is_valid_template_hash( $template_hash ) ) {
					delete_transient( Util::transient_key( 'wppo_ccss_attempts_' . $template_hash ) );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Read consecutive timeout failures.
		 *
		 * @param string $template_hash Template hash.
		 * @return int Timeout count.
		 * @since NEXT
		 */
		public static function get_timeout_attempts( string $template_hash ): int {
			try {
				if ( ! function_exists( 'get_transient' ) || ! Ccss_Store::is_valid_template_hash( $template_hash ) ) {
					return 0;
				}
				$stored = get_transient( Util::transient_key( 'wppo_ccss_timeout_' . $template_hash ) );
				return is_numeric( $stored ) ? max( 0, (int) $stored ) : 0;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Clear consecutive timeout failures and their first-seen stamp.
		 *
		 * @param string $template_hash Template hash.
		 * @return void
		 * @since NEXT
		 */
		public static function clear_timeout_attempts( string $template_hash ): void {
			try {
				if ( function_exists( 'delete_transient' ) && Ccss_Store::is_valid_template_hash( $template_hash ) ) {
					delete_transient( Util::transient_key( 'wppo_ccss_timeout_' . $template_hash ) );
					delete_transient( Util::transient_key( 'wppo_ccss_timeout_first_' . $template_hash ) );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Clear all bounded retry state for a template.
		 *
		 * @param string $template_hash Template hash.
		 * @return void
		 * @since NEXT
		 */
		public static function clear_retry_state( string $template_hash ): void {
			try {
				self::clear_generation_attempts( $template_hash );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			try {
				self::clear_timeout_attempts( $template_hash );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Read a generation status from salted cache or transient fallback.
		 *
		 * @param string $hash Template hash.
		 * @return string|false Status or false when unset.
		 * @since NEXT
		 */
		public static function get_status_cache( string $hash ) {
			$key = 'wppo_ccss_status_' . $hash;
			if ( function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				$cached = wp_cache_get_salted( $key, 'wppo', Util::cache_salt( self::SALT_KEY ) );
				return false !== $cached ? $cached : get_transient( Util::transient_key( $key ) );
			}
			return get_transient( Util::transient_key( $key ) );
		}

		/**
		 * Store a generation status in salted cache and transient fallback.
		 *
		 * @param string $hash   Template hash.
		 * @param string $status Status value.
		 * @param int    $ttl    Time to live in seconds.
		 * @return void
		 * @since NEXT
		 */
		public static function set_status_cache( string $hash, string $status, int $ttl ): void {
			$key = 'wppo_ccss_status_' . $hash;
			if ( function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				// @phpstan-ignore-next-line argument.type -- WordPress 6.9 runtime API is not in the local stub set.
				call_user_func( 'wp_cache_set_salted', $key, $status, 'wppo', Util::cache_salt( self::SALT_KEY ), $ttl );
			}
			set_transient( Util::transient_key( $key ), $status, $ttl );
		}

		/**
		 * Whether a generation job is pending or running in either AS group.
		 *
		 * @param string $hook      Action hook.
		 * @param array  $hook_args Action arguments.
		 * @return bool True when a matching action is live.
		 * @since NEXT
		 */
		public static function has_pending_job( string $hook, array $hook_args ): bool {
			if ( ! function_exists( 'as_next_scheduled_action' ) ) {
				return false;
			}
			try {
				$memo_key = md5( $hook . serialize( $hook_args ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Per-request memo key only.
			} catch ( \Throwable $e ) {
				unset( $e );
				$memo_key = '';
			}
			if ( '' !== $memo_key && array_key_exists( $memo_key, self::$pending_memo ) ) {
				return self::$pending_memo[ $memo_key ];
			}
			$pending = false;
			try {
				if ( (bool) as_next_scheduled_action( $hook, $hook_args, self::CCSS_AS_GROUP )
					|| (bool) as_next_scheduled_action( $hook, $hook_args, 'performance_optimisation' ) ) {
					$pending = true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( $pending ) {
				if ( '' !== $memo_key ) {
					self::$pending_memo[ $memo_key ] = true;
				}
				return true;
			}
			$result = false;
			try {
				if ( function_exists( 'as_get_scheduled_actions' ) && class_exists( \ActionScheduler_Store::class ) ) {
					foreach ( array( self::CCSS_AS_GROUP, 'performance_optimisation' ) as $ccss_group ) {
						$running = as_get_scheduled_actions(
							array(
								'hook'     => $hook,
								'args'     => $hook_args,
								'group'    => $ccss_group,
								'status'   => \ActionScheduler_Store::STATUS_RUNNING,
								'per_page' => 1,
							),
							'ids'
						);
						if ( is_array( $running ) && ! empty( $running ) ) {
							$result = true;
							break;
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( '' !== $memo_key ) {
				self::$pending_memo[ $memo_key ] = $result;
			}
			return $result;
		}

		/**
		 * Atomically enqueue one generation job with the historic group matrix.
		 *
		 * @param string $hook      Action hook.
		 * @param array  $hook_args Wrapped action arguments.
		 * @param int    $timestamp When the job should run.
		 * @return array{id:int,pending:bool} Enqueue result.
		 * @since NEXT
		 */
		public static function schedule_job( string $hook, array $hook_args, int $timestamp ): array {
			$none = array(
				'id'      => 0,
				'pending' => false,
			);
			try {
				if ( ! function_exists( 'as_enqueue_async_action' ) ) {
					return $none;
				}
				$legacy_group = 'performance_optimisation';
				$use_unique   = Util::supports_action_scheduler_unique();
				if ( $use_unique ) {
					$job_id = Util::schedule_unique_single_action( $timestamp, $hook, $hook_args, self::CCSS_AS_GROUP, array( $legacy_group ) );
					if ( $job_id > 0 ) {
						self::memoize_pending( $hook, $hook_args );
						return array(
							'id'      => $job_id,
							'pending' => true,
						);
					}
					return self::has_pending_job( $hook, $hook_args ) ? array(
						'id'      => 0,
						'pending' => true,
					) : $none;
				}
				if ( self::has_pending_job( $hook, $hook_args ) ) {
					return array(
						'id'      => 0,
						'pending' => true,
					);
				}
				$job_id = 0;
				if ( function_exists( 'as_schedule_single_action' ) ) {
					try {
						$job_id = (int) as_schedule_single_action( $timestamp, $hook, $hook_args, self::CCSS_AS_GROUP );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				} else {
					try {
						$job_id = (int) as_enqueue_async_action( $hook, $hook_args, self::CCSS_AS_GROUP );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( $job_id > 0 ) {
					self::memoize_pending( $hook, $hook_args );
					return array(
						'id'      => $job_id,
						'pending' => true,
					);
				}
				// PHPStan cannot see Action Scheduler's runtime-loaded lookup
				// function, so retain the fail-open post-enqueue re-check.
				// @phpstan-ignore-next-line -- Action Scheduler is loaded at runtime.
				if ( self::has_pending_job( $hook, $hook_args ) ) {
					return array(
						'id'      => 0,
						'pending' => true,
					);
				}
				return $none;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $none;
			}
		}

		/**
		 * Schedule a bounded exponential retry.
		 *
		 * @param string   $template_hash Template hash.
		 * @param int|null $attempts      Optional generic attempt count.
		 * @return array{id:int,pending:bool} Enqueue result.
		 * @since NEXT
		 */
		public static function schedule_retry( string $template_hash, ?int $attempts = null ): array {
			$none = array(
				'id'      => 0,
				'pending' => false,
			);
			if ( ! Ccss_Store::is_valid_template_hash( $template_hash ) ) {
				return $none;
			}
			try {
				$hook_args = array( array( 'template_hash' => $template_hash ) );
				if ( null === $attempts ) {
					$attempts = self::get_timeout_attempts( $template_hash );
				}
				$delay = 300 * ( 1 << min( max( $attempts - 1, 0 ), 3 ) );
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					return self::schedule_job( 'wppo_generate_ccss', $hook_args, time() + $delay );
				}
				if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) ) {
					if ( ! wp_next_scheduled( 'wppo_generate_ccss', $hook_args ) ) {
						$scheduled = (bool) wp_schedule_single_event( time() + $delay, 'wppo_generate_ccss', $hook_args );
						return array(
							'id'      => 0,
							'pending' => $scheduled,
						);
					}
					return array(
						'id'      => 0,
						'pending' => true,
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $none;
		}

		/**
		 * Record an ordinary generation failure and schedule a retry.
		 *
		 * @param string     $template_hash Template hash.
		 * @param int        $budget        Generation budget in seconds.
		 * @param float|null $deadline      Absolute deadline, if bounded.
		 * @return void
		 * @since NEXT
		 */
		public static function record_generation_failure( string $template_hash, int $budget, ?float $deadline ): void {
			if ( self::generation_expired( $deadline ) ) {
				self::handle_timeout( $template_hash, $budget );
				return;
			}
			$attempts = 0;
			$cap      = self::DEFAULT_CCSS_MAX_RETRIES;
			try {
				$cap      = self::get_ccss_max_retries();
				$attempts = self::get_generation_attempts( $template_hash ) + 1;
				if ( function_exists( 'set_transient' ) && Ccss_Store::is_valid_template_hash( $template_hash ) ) {
					set_transient( Util::transient_key( 'wppo_ccss_attempts_' . $template_hash ), $attempts, defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$total = $attempts;
			if ( $cap > 0 && $total < max( 1, $cap ) ) {
				try {
					$total = $attempts + self::get_timeout_attempts( $template_hash );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( $cap <= 0 || $total >= max( 1, $cap ) ) {
				try {
					self::set_status_cache( $template_hash, 'failed', defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
					if ( class_exists( Log::class ) ) {
						Log::add( sprintf( 'Critical CSS escalated to failed for %s after %d failures.', $template_hash, (int) $total ) );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				return;
			}
			$retry = self::schedule_retry( $template_hash, $attempts );
			try {
				self::set_status_cache( $template_hash, $retry['pending'] ? 'queued' : 'failed', $retry['pending'] ? ( defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 ) : ( defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Record a generation timeout and schedule bounded retry/escalation.
		 *
		 * @param string $template_hash Template hash.
		 * @param int    $budget        Generation budget in seconds.
		 * @return void
		 * @since NEXT
		 */
		public static function handle_timeout( string $template_hash, int $budget ): void {
			if ( ! Ccss_Store::is_valid_template_hash( $template_hash ) ) {
				return;
			}
			$attempts = 0;
			try {
				$attempts = self::get_timeout_attempts( $template_hash ) + 1;
				if ( function_exists( 'set_transient' ) ) {
					set_transient( Util::transient_key( 'wppo_ccss_timeout_' . $template_hash ), $attempts, defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
				}
				if ( function_exists( 'get_transient' ) && function_exists( 'set_transient' ) ) {
					$first_key = Util::transient_key( 'wppo_ccss_timeout_first_' . $template_hash );
					$first     = get_transient( $first_key );
					$day       = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
					if ( ! is_numeric( $first ) ) {
						set_transient( $first_key, time(), 2 * $day );
					} elseif ( ( time() - (int) $first ) > $day ) {
						$attempts = max( $attempts, self::MAX_CCSS_TIMEOUT_ATTEMPTS );
						set_transient( Util::transient_key( 'wppo_ccss_timeout_' . $template_hash ), $attempts, $day );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$retry_cap = self::MAX_CCSS_MAX_RETRIES;
			try {
				$retry_cap = self::get_ccss_max_retries();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$total = $attempts;
			if ( $retry_cap > 0 && $total < max( 1, $retry_cap ) ) {
				try {
					$total = $attempts + self::get_generation_attempts( $template_hash );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( $retry_cap <= 0 || $total >= max( 1, $retry_cap ) ) {
				try {
					self::set_status_cache( $template_hash, 'failed', defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
					if ( class_exists( Log::class ) ) {
						$message = sprintf( 'Critical CSS generation escalated to failed for template: %s after %d consecutive failures (timeout budget %d seconds).', $template_hash, $total, $budget );
						if ( function_exists( '__' ) ) {
							$message = sprintf(
								/* translators: 1: Template hash 2: Consecutive failure count 3: Timeout budget in seconds */
								__( 'Critical CSS generation escalated to failed for template: %1$s after %2$d consecutive failures (timeout budget %3$d seconds).', 'performance-optimisation' ),
								$template_hash,
								$total,
								$budget
							);
						}
						Log::add( $message );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				return;
			}
			$retry = self::schedule_retry( $template_hash );
			try {
				self::set_status_cache( $template_hash, $retry['pending'] ? 'queued' : 'failed', $retry['pending'] ? ( defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 ) : ( defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Whether an absolute generation deadline has expired.
		 *
		 * @param float|null $deadline Absolute deadline, or null for uncapped.
		 * @return bool True when expired.
		 * @since NEXT
		 */
		public static function generation_expired( ?float $deadline ): bool {
			if ( null === $deadline ) {
				return false;
			}
			$now = function_exists( 'microtime' ) ? microtime( true ) : (float) time();
			return (float) $now >= $deadline;
		}

		/**
		 * Bump the status-cache salt so all salted statuses are invalidated.
		 *
		 * @return void
		 * @since NEXT
		 */
		public static function bump_status_salt(): void {
			if ( function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				update_option( self::SALT_KEY, (int) get_option( self::SALT_KEY, 0 ) + 1, false );
			}
		}

		/**
		 * Reset the per-request pending-job memo.
		 *
		 * @return void
		 * @since NEXT
		 */
		public static function reset_pending_memo(): void {
			self::$pending_memo = array();
		}

		/**
		 * Memoize a confirmed pending action key.
		 *
		 * @param string $hook      Action hook.
		 * @param array  $hook_args Action arguments.
		 * @return void
		 */
		private static function memoize_pending( string $hook, array $hook_args ): void {
			try {
				self::$pending_memo[ md5( $hook . serialize( $hook_args ) ) ] = true; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Per-request memo key only.
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}
}
