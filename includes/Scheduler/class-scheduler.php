<?php
/**
 * Scheduler boundary — Action Scheduler unique-job helpers and stampede locks.
 *
 * Focused extraction (REF-014) of the scheduler responsibility cluster
 * previously owned by the god utility `Util`. Owns the Action Scheduler
 * unique-job dedup (support probe + memos, enqueue/schedule with atomic
 * `$unique` when available and legacy fail-open guards otherwise) and the
 * advisory stampede-lock verbs (guard toggle, TTL policy, owner tokens,
 * acquire/release). `Util::enqueue_unique_async_action()` and friends
 * remain as one-line facade proxies so all existing callers keep working
 * untouched.
 *
 * Lock semantics are concurrency-critical (advisory get→set, 2-5s TTLs,
 * owner-checked release) and are moved byte-identical: no CAS upgrade, no
 * caller migration, no perf changes.
 *
 * Minimal WordPress APIs only: `as_enqueue_async_action()`,
 * `as_schedule_single_action()`, `as_has_scheduled_action()`,
 * `wp_next_scheduled()`, `wp_schedule_event()`,
 * `wp_schedule_single_event()` (all probed
 * via `function_exists()`, never required), `apply_filters()`,
 * `wp_generate_uuid4()`, `wp_rand()`, `wp_using_ext_object_cache()`,
 * `wp_cache_add()` / `wp_cache_get()` / `wp_cache_delete()`,
 * `get_transient()` / `set_transient()` / `delete_transient()`, plus the
 * cross-boundary `Settings_Store::get_settings()` (guard toggle + TTL policy),
 * resolved at call time via the spl autoloader so there is no load-time
 * cycle.
 *
 * Deliberately OUT (stays in `Util`): `stampede_stale_key()` key
 * construction (owned by `Cache_Key`, REF-001);
 * `register_transient_index_key()` (transient-index ownership);
 * `get_with_stampede_lock()` and `get_alloptions_with_stampede_lock()`
 * (cache-read paths that merely USE the lock verbs — their internals now
 * delegate to this boundary).
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Scheduler' ) ) {
	/**
	 * Class Scheduler
	 *
	 * Static scheduler boundary. Depends only on the minimal WordPress /
	 * Action Scheduler APIs required to preserve the existing implementation
	 * verbatim (see file docblock) plus the single `Settings_Store::get_settings()`
	 * call for the guard toggle and TTL policy. `Util` proxies back at call
	 * time only (autoloader, no load-time cycle).
	 *
	 * @since 2.4.0
	 */
	final class Scheduler {

		/**
		 * Memoized probe result for {@see supports_action_scheduler_unique()}.
		 *
		 * Null until the first probe runs; afterwards true/false for the
		 * remainder of the request.
		 *
		 * @var bool|null
		 */
		private static ?bool $as_unique_support = null;

		/**
		 * Memoized per-function `$unique`-parameter arity probes.
		 *
		 * Maps function name => bool so the single/recurring helpers do not
		 * repeat reflection on every call.
		 *
		 * @var array<string, bool>
		 */
		private static array $as_unique_arity = array();

		/**
		 * Whether the loaded Action Scheduler supports atomic unique actions.
		 *
		 * Action Scheduler 4.x added a `$unique` parameter (after `$group`,
		 * before `$priority`) to `as_enqueue_async_action()`,
		 * `as_schedule_single_action()` and `as_schedule_recurring_action()`
		 * so hook+args+group deduplication happens atomically in the store
		 * instead of via a racy check-then-act. Fail-open: returns false when
		 * the scheduler is absent, older than 4.x, or reflection fails.
		 *
		 * The probe result is memoized in a static so bulk paths
		 * (regenerate_all loops, crawler bursts) pay the reflection and
		 * `ActionScheduler_Versions` lookup once per request instead of once
		 * per job (issue #1310 review). Use
		 * {@see reset_action_scheduler_unique_cache()} to clear the memo in
		 * tests.
		 *
		 * @since 2.2.0
		 * @return bool True when the `$unique` parameter may be passed.
		 */
		public static function supports_action_scheduler_unique(): bool {
			if ( null !== self::$as_unique_support ) {
				return self::$as_unique_support;
			}
			self::$as_unique_support = self::probe_action_scheduler_unique();
			return self::$as_unique_support;
		}

		/**
		 * Reset the memoized Action Scheduler unique-support probes.
		 *
		 * Test-only seam: production code never needs to re-probe within a
		 * request, but unit tests that swap scheduler stubs between cases
		 * must clear the memo to observe the new stub.
		 *
		 * @since 2.2.0
		 * @return void
		 */
		public static function reset_action_scheduler_unique_cache(): void {
			self::$as_unique_support = null;
			self::$as_unique_arity   = array();
		}

		/**
		 * Unmemoized Action Scheduler unique-support probe.
		 *
		 * @since 2.2.0
		 * @return bool True when the `$unique` parameter may be passed.
		 */
		private static function probe_action_scheduler_unique(): bool {
			try {
				if ( ! function_exists( 'as_enqueue_async_action' ) ) {
					return false;
				}
				if ( class_exists( 'ActionScheduler_Versions' ) && method_exists( 'ActionScheduler_Versions', 'instance' ) ) {
					try {
						$versions = \ActionScheduler_Versions::instance();
						if ( is_object( $versions ) && method_exists( $versions, 'latest_version' ) ) {
							$latest = $versions->latest_version();
							if ( is_string( $latest ) && '' !== $latest && version_compare( $latest, '4.0', '<' ) ) {
								return false;
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				return self::function_has_unique_param( 'as_enqueue_async_action', 4 );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether a scheduler function accepts the AS 4.x `$unique` parameter.
		 *
		 * Memoized per function name: repeated reflection is paid once per
		 * request no matter how many jobs a bulk path enqueues.
		 *
		 * @since 2.2.0
		 * @param string $function_name Function name to inspect.
		 * @param int    $min_params    Minimum parameter count that implies `$unique` support.
		 * @return bool True when the function exists and declares at least `$min_params` parameters.
		 */
		private static function function_has_unique_param( string $function_name, int $min_params ): bool {
			if ( array_key_exists( $function_name, self::$as_unique_arity ) ) {
				return self::$as_unique_arity[ $function_name ];
			}
			try {
				if ( ! function_exists( $function_name ) ) {
					self::$as_unique_arity[ $function_name ] = false;
					return false;
				}
				$ref                                     = new \ReflectionFunction( $function_name );
				self::$as_unique_arity[ $function_name ] = $ref->getNumberOfParameters() >= $min_params;
				return self::$as_unique_arity[ $function_name ];
			} catch ( \Throwable $e ) {
				unset( $e );
				self::$as_unique_arity[ $function_name ] = false;
				return false;
			}
		}

		/**
		 * Fail-open "already scheduled" guard shared by the unique helpers.
		 *
		 * Single home for the legacy `as_has_scheduled_action()` check so
		 * future guard fixes land on all three helpers at once (issue #1310
		 * review). A missing or throwing guard degrades to "not scheduled"
		 * rather than failing the enqueue.
		 *
		 * @since 2.2.0
		 * @param string $hook  Action hook.
		 * @param array  $args  Action arguments.
		 * @param string $group Action group.
		 * @return bool True when a matching action is already scheduled.
		 */
		private static function as_already_scheduled( string $hook, array $args, string $group ): bool {
			if ( ! function_exists( 'as_has_scheduled_action' ) ) {
				return false;
			}
			try {
				return (bool) as_has_scheduled_action( $hook, $args, $group );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Fail-open "already scheduled" check across several AS groups.
		 *
		 * The CCSS pipeline schedules on the dedicated `wppo-ccss` group but
		 * must still dedupe against pre-split jobs in the legacy
		 * `performance_optimisation` group; a guard that only probes the
		 * passed group would double-schedule on pre-4.x schedulers when a
		 * legacy-group job lands between checks (issue #1310 review).
		 * Callers pass the extra groups so every legacy fallback below
		 * probes the same disjunction the atomic path dedupes.
		 *
		 * @since 2.2.0
		 * @param string   $hook         Action hook.
		 * @param array    $args         Action arguments.
		 * @param string   $group        Primary action group.
		 * @param string[] $extra_groups Additional groups to probe.
		 * @return bool True when a matching action is already scheduled in any of the groups.
		 */
		private static function as_already_scheduled_in_any_group( string $hook, array $args, string $group, array $extra_groups = array() ): bool {
			if ( self::as_already_scheduled( $hook, $args, $group ) ) {
				return true;
			}
			foreach ( $extra_groups as $extra_group ) {
				if ( ! is_string( $extra_group ) || '' === $extra_group || $extra_group === $group ) {
					continue;
				}
				if ( self::as_already_scheduled( $hook, $args, $extra_group ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Enqueue an async Action Scheduler job with atomic dedup when available.
		 *
		 * Tries `as_enqueue_async_action( $hook, $args, $group, true )` first so
		 * concurrent processes cannot double-insert the same hook+args+group;
		 * falls back to the legacy `as_has_scheduled_action()` guard plus a
		 * 3-argument enqueue on older scheduler versions. Never fatal: any
		 * scheduler API failure returns 0.
		 *
		 * @since 2.2.0
		 * @param string   $hook         Action hook.
		 * @param array    $args         Action arguments.
		 * @param string   $group        Action group.
		 * @param string[] $extra_groups Additional groups probed by the legacy fallback guard (e.g. the CCSS legacy group).
		 * @return int Action ID, or 0 when deduped, unavailable, or on failure.
		 */
		public static function enqueue_unique_async_action( string $hook, array $args = array(), string $group = '', array $extra_groups = array() ): int {
			try {
				if ( ! function_exists( 'as_enqueue_async_action' ) ) {
					return 0;
				}
				if ( self::supports_action_scheduler_unique() ) {
					// Cross-group dedupe (issue #1310 review): the AS 4.x
					// `$unique` flag dedupes per-group only, so a pending
					// job in an extra (legacy) group would not block the
					// insert below. Probe the extra groups up front; the
					// both-group re-check on a 0 return stays as the
					// race backstop.
					foreach ( $extra_groups as $extra_group ) {
						if ( ! is_string( $extra_group ) || '' === $extra_group || $extra_group === $group ) {
							continue;
						}
						if ( self::as_already_scheduled( $hook, $args, $extra_group ) ) {
							return 0;
						}
					}
					try {
						$result = as_enqueue_async_action( $hook, $args, $group, true );
						return (int) $result;
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				// Legacy guard (fail-open — see as_already_scheduled()).
				if ( self::as_already_scheduled_in_any_group( $hook, $args, $group, $extra_groups ) ) {
					return 0;
				}
				return (int) as_enqueue_async_action( $hook, $args, $group );
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Schedule a one-off Action Scheduler job with atomic dedup when available.
		 *
		 * Same fail-open contract as {@see enqueue_unique_async_action()} but
		 * for delayed single actions.
		 *
		 * @since 2.2.0
		 * @param int      $timestamp    When the job will run.
		 * @param string   $hook         Action hook.
		 * @param array    $args         Action arguments.
		 * @param string   $group        Action group.
		 * @param string[] $extra_groups Additional groups probed by the legacy fallback guard (e.g. the CCSS legacy group).
		 * @return int Action ID, or 0 when deduped, unavailable, or on failure.
		 */
		public static function schedule_unique_single_action( int $timestamp, string $hook, array $args = array(), string $group = '', array $extra_groups = array() ): int {
			try {
				if ( ! function_exists( 'as_schedule_single_action' ) ) {
					return 0;
				}
				if ( self::supports_action_scheduler_unique() ) {
					// Cross-group dedupe (issue #1310 review): see
					// enqueue_unique_async_action() — probe extra groups
					// before the per-group atomic insert.
					foreach ( $extra_groups as $extra_group ) {
						if ( ! is_string( $extra_group ) || '' === $extra_group || $extra_group === $group ) {
							continue;
						}
						if ( self::as_already_scheduled( $hook, $args, $extra_group ) ) {
							return 0;
						}
					}
					try {
						if ( self::function_has_unique_param( 'as_schedule_single_action', 5 ) ) {
							return (int) as_schedule_single_action( $timestamp, $hook, $args, $group, true );
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				// Legacy guard (fail-open — see as_already_scheduled()).
				if ( self::as_already_scheduled_in_any_group( $hook, $args, $group, $extra_groups ) ) {
					return 0;
				}
				return (int) as_schedule_single_action( $timestamp, $hook, $args, $group );
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Probe when a WP-Cron event is next scheduled.
		 *
		 * Thin fail-open probe over `wp_next_scheduled()`: returns false when
		 * the WP-Cron API is unavailable or the probe throws, so callers can
		 * share one choke point instead of hand-rolling `function_exists()`
		 * guards (ARCH-012). Read-only: never schedules.
		 *
		 * @since 2.4.0
		 * @param string $hook Cron hook name.
		 * @param array  $args Optional event args.
		 * @return int|false Timestamp of the next run, or false when unscheduled/unavailable.
		 */
		public static function next_scheduled( string $hook, array $args = array() ) {
			try {
				if ( ! function_exists( 'wp_next_scheduled' ) ) {
					return false;
				}
				return wp_next_scheduled( $hook, $args );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Schedule a recurring WP-Cron event once (idempotent).
		 *
		 * Next-scheduled guard + schedule in one choke point: when the hook
		 * +args set is already scheduled the call is a no-op returning true;
		 * otherwise `wp_schedule_event()` runs. Fail-open false when the
		 * WP-Cron API is missing or throws. Hook name, timestamp,
		 * recurrence, and args pass through unchanged.
		 *
		 * @since 2.4.0
		 * @param string $hook       Cron hook name.
		 * @param int    $timestamp  First run timestamp (e.g. `time()`).
		 * @param string $recurrence Schedule recurrence (e.g. `daily`).
		 * @param array  $args       Optional event args (dedup is per args-set).
		 * @return bool True when already scheduled or newly scheduled, false when unavailable/failed.
		 */
		public static function schedule_recurring_event( string $hook, int $timestamp, string $recurrence, array $args = array() ): bool {
			try {
				if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
					return false;
				}
				if ( self::next_scheduled( $hook, $args ) ) {
					return true;
				}
				return (bool) wp_schedule_event( $timestamp, $recurrence, $hook, $args );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Schedule a one-off WP-Cron event.
		 *
		 * Availability guard + schedule in one choke point. Unlike
		 * {@see schedule_recurring_event()}, no next-scheduled dedup is
		 * applied here: single events carry per-job args (page IDs, URLs,
		 * batches) where a hook-level guard would collapse distinct jobs
		 * into one. Callers that need hook-level dedup probe
		 * {@see next_scheduled()} first (e.g. `wppo_page_cron_batch`).
		 * Fail-open false when the WP-Cron API is missing or throws.
		 *
		 * @since 2.4.0
		 * @param int    $timestamp When the event will run.
		 * @param string $hook      Cron hook name.
		 * @param array  $args      Optional event args.
		 * @return bool True on schedule, false when unavailable/failed.
		 */
		public static function schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
			try {
				if ( ! function_exists( 'wp_schedule_single_event' ) ) {
					return false;
				}
				return (bool) wp_schedule_single_event( $timestamp, $hook, $args );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether the stampede guard is enabled.
		 *
		 * Operator opt-out via `wppo_settings['cache_settings']['stampedeGuard']`
		 * (default true, additive key) or the `wppo_stampede_guard_enabled`
		 * filter. When disabled, {@see get_with_stampede_lock()} degrades to a
		 * plain get-or-rebuild without coalescing (fail-open).
		 *
		 * @since 2.2.0
		 * @return bool True when coalescing is active.
		 */
		public static function is_stampede_guard_enabled(): bool {
			try {
				$settings = Settings_Store::get_settings();
				$guard    = $settings['cache_settings']['stampedeGuard'] ?? true;
			} catch ( \Throwable $e ) {
				$guard = true;
			}
			/**
			 * Filters whether the stampede guard coalesces hot-key rebuilds.
			 *
			 * @since 2.2.0
			 * @param bool $enabled Whether the guard is enabled.
			 */
			return (bool) apply_filters( 'wppo_stampede_guard_enabled', (bool) $guard );
		}

		/**
		 * Effective stampede lock TTL in seconds, clamped to 2-5s.
		 *
		 * Reads `wppo_settings['cache_settings']['stampedeLockTtl']` (default 5,
		 * additive key) with the `wppo_stampede_lock_ttl` filter applied last.
		 * The tight bound keeps a crashed holder from stalling rebuilds while
		 * still covering typical DB/HTTP rebuilds under herd. It is
		 * best-effort: rebuilds slower than the TTL (slow-origin telemetry
		 * fetches) may expire mid-rebuild and duplicate work rather than stall.
		 *
		 * @since 2.2.0
		 * @return int Lock TTL clamped to 2-5 seconds.
		 */
		public static function stampede_lock_ttl(): int {
			$ttl = 5;
			try {
				$settings = Settings_Store::get_settings();
				$raw      = $settings['cache_settings']['stampedeLockTtl'] ?? 5;
				$ttl      = (int) $raw;
			} catch ( \Throwable $e ) {
				$ttl = 5;
			}
			/**
			 * Filters the stampede lock TTL in seconds (clamped to 2-5s after filtering).
			 *
			 * @since 2.2.0
			 * @param int $ttl Lock TTL in seconds.
			 */
			$ttl = (int) apply_filters( 'wppo_stampede_lock_ttl', $ttl );
			if ( $ttl < 2 ) {
				return 2;
			}
			if ( $ttl > 5 ) {
				return 5;
			}
			return $ttl;
		}

		/**
		 * Generate a unique stampede lock owner token.
		 *
		 * Prefers `wp_generate_uuid4()` with a `uniqid() + mt_rand()` fallback
		 * so concurrent workers never share an owner (release is owner-checked).
		 *
		 * @since 2.2.0
		 * @return string Unique owner token (never empty).
		 */
		public static function generate_stampede_owner(): string {
			try {
				if ( function_exists( 'wp_generate_uuid4' ) ) {
					$uuid = (string) wp_generate_uuid4();
					if ( '' !== $uuid ) {
						return $uuid;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			try {
				if ( function_exists( 'wp_rand' ) ) {
					return uniqid( 'wppo-', true ) . '-' . wp_rand( 1, PHP_INT_MAX );
				}
				return uniqid( 'wppo-', true ) . '-' . random_int( 1, PHP_INT_MAX );
			} catch ( \Throwable $e ) {
				unset( $e );
				return 'wppo-' . microtime( true ) . '-' . uniqid();
			}
		}

		/**
		 * Atomically acquire a named stampede lock.
		 *
		 * Uses `wp_cache_add()` (atomic `SET NX EX` on Redis/Memcached) with a
		 * unique owner so only one worker wins — but only when a persistent
		 * object cache is present (`wp_using_ext_object_cache()`). Without a
		 * persistent cache `wp_cache_add()` is per-request in-memory only, so
		 * every worker would "acquire" the lock; the transient check-and-set
		 * fallback below is then used instead and is documented best-effort
		 * (two workers can both observe a miss and both claim the lock — it
		 * narrows the race but is not atomic unless serialized via an atomic
		 * option/DB row). Fail-open: any throwable means "not acquired"
		 * (caller serves stale).
		 *
		 * @since 2.2.0
		 * @param string $lock_key Blog-aware lock key (use transient_key()).
		 * @param string $owner    Unique owner token from generate_stampede_owner().
		 * @param int    $ttl      Lock TTL in seconds (clamped to 2-5s; best-effort
		 *                         bound — rebuilds slower than the TTL, e.g. a slow-
		 *                         origin telemetry fetch, may let the lock expire
		 *                         mid-rebuild and duplicate work).
		 * @param string $group    Object-cache group for the lock.
		 * @return bool True when this worker owns the lock.
		 */
		public static function acquire_stampede_lock( string $lock_key, string $owner, int $ttl = 5, string $group = 'wppo' ): bool {
			if ( '' === $lock_key || '' === $owner ) {
				return false;
			}
			if ( $ttl < 2 ) {
				$ttl = 2;
			} elseif ( $ttl > 5 ) {
				$ttl = 5;
			}
			try {
				$has_ext_cache = false;
				if ( function_exists( 'wp_using_ext_object_cache' ) ) {
					try {
						$has_ext_cache = (bool) wp_using_ext_object_cache();
					} catch ( \Throwable $e ) {
						unset( $e );
						$has_ext_cache = false;
					}
				}
				if ( $has_ext_cache && function_exists( 'wp_cache_add' ) ) {
					return (bool) wp_cache_add( $lock_key, $owner, $group, $ttl );
				}
				if ( function_exists( 'get_transient' ) && function_exists( 'set_transient' ) ) {
					if ( false !== get_transient( $lock_key ) ) {
						return false;
					}
					return (bool) set_transient( $lock_key, $owner, $ttl );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
			return false;
		}

		/**
		 * Release a stampede lock only when this worker still owns it.
		 *
		 * Owner-checked so a slow worker never deletes a successor's lock after
		 * its own TTL expired. Fail-open: throwables are swallowed (never fatal).
		 *
		 * @since 2.2.0
		 * @param string $lock_key Blog-aware lock key.
		 * @param string $owner    Owner token that acquired the lock.
		 * @param string $group    Object-cache group for the lock.
		 * @return void
		 */
		public static function release_stampede_lock( string $lock_key, string $owner, string $group = 'wppo' ): void {
			if ( '' === $lock_key || '' === $owner ) {
				return;
			}
			try {
				$current = null;
				$found   = false;
				if ( function_exists( 'wp_cache_get' ) ) {
					$current = wp_cache_get( $lock_key, $group );
					$found   = ( $current === $owner );
				} elseif ( function_exists( 'get_transient' ) ) {
					$current = get_transient( $lock_key );
					$found   = ( $current === $owner );
				}
				if ( ! $found ) {
					// Fall back to the transient namespace: the lock may have
					// been stored there when wp_cache_add() was unavailable.
					if ( function_exists( 'get_transient' ) && $current !== $owner ) {
						$transient_current = get_transient( $lock_key );
						$found             = ( $transient_current === $owner );
					}
				}
				if ( ! $found ) {
					return;
				}
				if ( function_exists( 'wp_cache_delete' ) ) {
					wp_cache_delete( $lock_key, $group );
				}
				if ( function_exists( 'delete_transient' ) ) {
					delete_transient( $lock_key );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}
}
