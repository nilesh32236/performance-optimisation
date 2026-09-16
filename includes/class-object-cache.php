<?php
/**
 * Object Cache Manager.
 *
 * Handles Redis drop-in installation, removal, and testing.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.4.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Object_Cache' ) ) {
	/**
	 * Class Object_Cache
	 *
	 * @since 1.4.0
	 */
	class Object_Cache {

		/**
		 * Marker inside object-cache.php drop-in so we do not overwrite or delete other plugins' files.
		 *
		 * @var string
		 */
		public const DROPIN_MARKER = 'Redis Object Cache Drop-in for Performance Optimisation';

		/**
		 * Legacy marker for backward compatibility.
		 *
		 * @var string
		 * @since 1.4.0
		 */
		public const LEGACY_DROPIN_MARKER = 'Redis Object Cache Drop-in';

		/**
		 * Option name storing the plugin-side circuit-breaker state.
		 *
		 * The drop-in (templates/object-cache.php) trips itself at early boot
		 * and bridges state via WP_CONTENT_DIR/wppo-redis-disabled.json; this
		 * option mirrors that state for fully-booted WordPress (admin notices,
		 * cron probe scheduling, SPA status).
		 *
		 * @since 2.0.0
		 * @var string
		 */
		public const CIRCUIT_OPTION = 'wppo_object_cache_circuit';

		/**
		 * Transient key (blog-prefixed via Util::transient_key()) mirroring
		 * the drop-in failure counter for admin UI and cron use.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		public const FAIL_TRANSIENT = 'wppo_redis_failures';

		/**
		 * Admin-notice transient key (blog-prefixed via Util::transient_key())
		 * armed when the circuit trips and cleared on dismiss/recovery.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		public const CIRCUIT_NOTICE_TRANSIENT = 'wppo_object_cache_circuit_notice';

		/**
		 * Option name storing the tripped_at timestamp the admin notice was
		 * dismissed for. A later trip carries a newer timestamp, which
		 * automatically re-arms the notice.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		public const CIRCUIT_DISMISSED_OPTION = 'wppo_object_cache_circuit_dismissed';

		/**
		 * Parked drop-in suffix used when the breaker auto-disables.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		public const PARKED_SUFFIX = '.wppo-disabled';

		/**
		 * File bridging the drop-in trip to fully-booted WordPress.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		public const DISABLED_STATE_FILE = 'wppo-redis-disabled.json';

		/**
		 * Drop-in failure-counter file (JSON { count, first, last }).
		 *
		 * @since 2.0.0
		 * @var string
		 */
		public const FAILURES_FILE = 'wppo-redis-failures.json';

		/**
		 * Allowed Redis configuration keys (single source for REST + CLI).
		 *
		 * Converges CLI 6-key allowlist (host,port,password,database,timeout,prefix)
		 * with REST 10-key allowlist (mode,host,port,password,database,nodes,master_name,use_tls,persistent,compression)
		 * to prevent silent drops for Sentinel/Cluster/TLS options.
		 *
		 * @since 2.0.0
		 * @var string[]
		 */
		public const ALLOWED_KEYS = array( 'mode', 'host', 'port', 'password', 'database', 'timeout', 'prefix', 'nodes', 'master_name', 'use_tls', 'persistent', 'compression' );

		/**
		 * Marker used for the wp-content/.htaccess deny block shielding the
		 * Redis config file (see protect_config_file()).
		 *
		 * Exposed so the standalone uninstall context can remove the orphan
		 * block without hard-coding the string.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const CONFIG_HTACCESS_MARKER = 'WPPO Redis Config';

		/**
		 * Suffix of the staging sibling used for atomic Redis config writes.
		 *
		 * `write_config_atomic()` stages new config at
		 * `wppo-redis-config.php` + this suffix, verifies it, then renames it
		 * over the live path — so a process killed mid-write can never leave
		 * a half-written live file that would fatal the object-cache drop-in
		 * (which does a bare `include` of the config) on the next request.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const CONFIG_TMP_SUFFIX = '.tmp';

		/**
		 * Canonical circuit-breaker sidecar paths for uninstall cleanup.
		 *
		 * Standalone-safe: static, no instance, no filesystem, no filters —
		 * derived purely from WP_CONTENT_DIR plus the DISABLED_STATE_FILE /
		 * FAILURES_FILE constants and the canonical drop-in location plus
		 * PARKED_SUFFIX. The uninstall script prefers this helper (via
		 * class_exists() + method_exists() guards) and falls back to literal
		 * WP_CONTENT_DIR-joined filenames when the class is not loadable.
		 *
		 * Canonical-only limitation: the live parked sibling derives from the
		 * `wppo_object_cache_dropin_path`-filtered drop-in path, which is not
		 * resolved here — filters are unavailable in the standalone uninstall
		 * context, so a filtered install may leave its parked sibling behind.
		 * Only the canonical WP_CONTENT_DIR/object-cache.php.wppo-disabled
		 * path is returned.
		 *
		 * @since NEXT
		 * @return string[] Absolute paths (empty when WP_CONTENT_DIR is undefined).
		 */
		public static function get_uninstall_sidecar_paths(): array {
			if ( ! defined( 'WP_CONTENT_DIR' ) ) {
				return array();
			}
			$content_dir = (string) WP_CONTENT_DIR;
			if ( '' === $content_dir ) {
				return array();
			}
			return array(
				$content_dir . '/' . self::DISABLED_STATE_FILE,
				$content_dir . '/' . self::FAILURES_FILE,
				$content_dir . '/object-cache.php' . self::PARKED_SUFFIX,
			);
		}

		/**
		 * Path to the object cache drop-in.
		 *
		 * @var string
		 */
		private $dropin_path;

		/**
		 * Path to the config file.
		 *
		 * @var string
		 */
		private $config_path;

		/**
		 * Path to the template file.
		 *
		 * @var string
		 */
		private $template_path;

		/**
		 * Constructor function.
		 *
		 * @since 1.4.0
		 * @since 2.0.0 Filtered drop-in paths are validated for wp-content containment.
		 */
		public function __construct() {
			$default_dropin = wp_normalize_path( WP_CONTENT_DIR . '/object-cache.php' );
			$filtered       = apply_filters( 'wppo_object_cache_dropin_path', WP_CONTENT_DIR . '/object-cache.php' );

			// Non-string filter returns (arrays/objects) are rejected outright —
			// casting would raise an Array-to-string conversion warning in PHP 8.
			$dropin_path = is_string( $filtered ) ? wp_normalize_path( $filtered ) : $default_dropin;

			// Path containment (audit #888 finding 22): the filter must never
			// point reads/writes/deletes outside wp-content. Reject empty paths,
			// traversal segments, and anything outside WP_CONTENT_DIR, falling
			// back to the canonical drop-in location.
			$content_dir = wp_normalize_path( WP_CONTENT_DIR );
			if (
				'' === $dropin_path
				|| false !== strpos( $dropin_path, '..' )
				|| 0 !== strpos( $dropin_path, $content_dir . '/' )
			) {
				$dropin_path = $default_dropin;
			}

			$this->dropin_path   = $dropin_path;
			$this->config_path   = WP_CONTENT_DIR . '/wppo-redis-config.php';
			$this->template_path = WPPO_PLUGIN_PATH . 'templates/object-cache.php';
		}

		/**
		 * Active drop-in path (containment-validated).
		 *
		 * Consumers (e.g. System_Info::detect_dropin_ownership()) must use this
		 * instead of assuming the canonical WP_CONTENT_DIR location, so a
		 * relocated drop-in is still reported correctly.
		 *
		 * @since 2.0.0
		 * @return string
		 */
		public function get_dropin_path(): string {
			return $this->dropin_path;
		}

		/**
		 * Retrieve current status of the object cache and Redis connectivity.
		 *
		 * Returns an associative array with keys:
		 * - `enabled`: `true` if the plugin's drop-in is installed.
		 * - `redis_missing`: `true` if the PHP `Redis` extension is not available.
		 * - `redis_reachable`: `true` if a Redis connection can be established.
		 * - `foreign_dropin`: `true` if an existing `object-cache.php` without the plugin marker was found.
		 * - `telemetry` (optional): array of Redis information (redis_version, uptime_in_seconds, uptime_in_days, connected_clients, used_memory_human, used_memory_peak_human, total_connections_received, keyspace_hits, keyspace_misses, keys).
		 * - `telemetry_error` (optional): error message when telemetry collection failed.
		 *
		 * @since 1.4.0
		 * @return array The status array described above.
		 */
		public function get_status() {
			$status = array(
				'enabled'            => false,
				'redis_missing'      => ! class_exists( 'Redis' ),
				'redis_reachable'    => false,
				'foreign_dropin'     => false,
				'circuit_open'       => false,
				'circuit_tripped_at' => 0,
				'circuit_reason'     => '',
				'circuit_error_code' => '',
				'failure_count'      => 0,
			);

			// Circuit state is file/option-backed (no Redis needed), so it is
			// reported even when the extension is missing or Redis is down.
			$circuit                      = $this->get_circuit_state();
			$status['circuit_open']       = $circuit['open'];
			$status['circuit_tripped_at'] = $circuit['tripped_at'];
			$status['circuit_reason']     = $circuit['reason'];
			$status['circuit_error_code'] = $circuit['error_code'];
			$status['failure_count']      = $circuit['failures'];
			$status['serializers']        = $this->get_serializer_support();
			$status['last_failure']       = $this->get_last_failure_payload();

			if ( file_exists( $this->dropin_path ) ) {
				$wp_filesystem = Util::init_filesystem();

				if ( is_readable( $this->dropin_path ) && filesize( $this->dropin_path ) < 1048576 ) {
					if ( $wp_filesystem ) {
						$content = $wp_filesystem->get_contents( $this->dropin_path );
					} else {
						$content = file_get_contents( $this->dropin_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					}
				}

				if ( isset( $content ) && is_string( $content ) ) {
					if ( self::is_own_dropin_content( $content ) ) {
						$status['enabled'] = true;
					} else {
						$status['foreign_dropin'] = true;
					}
				}
			}

			if ( $status['redis_missing'] ) {
				return $status;
			}

			try {
				$config = $this->get_redis_config();

				$connection = $this->connect_internal( $config );

				if ( is_wp_error( $connection ) ) {
					$status['telemetry_error'] = defined( 'WP_DEBUG' ) && WP_DEBUG ? $connection->get_error_message() : __( 'Redis connection error.', 'performance-optimisation' );
					return $status;
				}

				$status['redis_reachable'] = true;
				$redis                     = $connection;

				try {
					// Collect telemetry if enabled and reachable.
					if ( $status['enabled'] ) {
						$info = $redis->info();
						if ( $info ) {
							$keys    = 0;
							$db_info = '';
							if ( $connection instanceof \RedisCluster && is_array( $info ) ) {
								// Cluster mode returns info keyed per node; extract from first node.
								$first_node_info = reset( $info );
								if ( isset( $first_node_info['db0'] ) ) {
									$db_info = $first_node_info['db0'];
								}
							} elseif ( isset( $info['db0'] ) ) {
								$db_info = $info['db0'];
							}
							// is_string() guard: phpredis returns db0 as a string
							// in some versions/modes and as a parsed array in
							// others, and preg_match() on an array throws a
							// TypeError on PHP 8, fataling the admin/REST status
							// path. Fall through with keys=0 when it is not text.
							if ( is_string( $db_info ) && '' !== $db_info && preg_match( '/keys=([0-9]+)/', $db_info, $matches ) ) {
								$keys = (int) $matches[1];
							}

							$status['telemetry'] = array(
								'redis_version'          => $info['redis_version'] ?? 'Unknown',
								'uptime_in_seconds'      => (int) ( $info['uptime_in_seconds'] ?? 0 ),
								'uptime_in_days'         => $info['uptime_in_days'] ?? 0,
								'connected_clients'      => $info['connected_clients'] ?? 0,
								'used_memory_human'      => $info['used_memory_human'] ?? '0B',
								'used_memory_peak_human' => $info['used_memory_peak_human'] ?? '0B',
								'total_connections_received' => $info['total_connections_received'] ?? 0,
								'keyspace_hits'          => $info['keyspace_hits'] ?? 0,
								'keyspace_misses'        => $info['keyspace_misses'] ?? 0,
								'keys'                   => $keys,
							);
						}
					}
				} catch ( \Exception $te ) {
					$status['telemetry_error'] = defined( 'WP_DEBUG' ) && WP_DEBUG ? $te->getMessage() : __( 'Redis telemetry error.', 'performance-optimisation' );
				} finally {
					if ( method_exists( $redis, 'close' ) ) {
						$redis->close();
					}
				}
			} catch ( \Exception $e ) {
				$status['redis_reachable'] = false;
				$status['telemetry_error'] = defined( 'WP_DEBUG' ) && WP_DEBUG ? $e->getMessage() : __( 'Redis connection error.', 'performance-optimisation' );
			}

			return $status;
		}

		/**
		 * Read the merged circuit-breaker state.
		 *
		 * Sources (first non-empty wins per field): the CIRCUIT_OPTION mirror
		 * written by auto_disable_circuit(), the wppo-redis-disabled.json
		 * bridge written by the drop-in's trip_circuit_breaker(), the parked
		 * object-cache.php.wppo-disabled sibling, and the wppo-redis-failures.json
		 * counter for the failure count.
		 *
		 * @since 2.0.0
		 * @return array Shape { open: bool, tripped_at: int, reason: string, error_code: string, failures: int }.
		 */
		public function get_circuit_state(): array {
			$state = array(
				'open'       => false,
				'tripped_at' => 0,
				'reason'     => '',
				'error_code' => '',
				'failures'   => 0,
			);

			$option = get_option( self::CIRCUIT_OPTION, array() );
			if ( is_array( $option ) ) {
				if ( ! empty( $option['open'] ) ) {
					$state['open'] = true;
				}
				if ( isset( $option['tripped_at'] ) ) {
					$state['tripped_at'] = (int) $option['tripped_at'];
				}
				if ( ! empty( $option['reason'] ) ) {
					$state['reason'] = (string) $option['reason'];
				}
				if ( ! empty( $option['error_code'] ) ) {
					$state['error_code'] = (string) $option['error_code'];
				}
				if ( isset( $option['failures'] ) ) {
					$state['failures'] = (int) $option['failures'];
				}
			}

			// Drop-in bridge: the early-boot trip cannot touch options, so it
			// leaves JSON state behind for fully-booted WordPress to pick up.
			$bridge = $this->read_json_state_file( $this->get_disabled_state_path() );
			if ( is_array( $bridge ) ) {
				$state['open'] = true;
				if ( 0 === $state['tripped_at'] && isset( $bridge['tripped_at'] ) ) {
					$state['tripped_at'] = (int) $bridge['tripped_at'];
				}
				if ( '' === $state['reason'] && ! empty( $bridge['reason'] ) ) {
					$state['reason'] = (string) $bridge['reason'];
				}
				if ( '' === $state['error_code'] && ! empty( $bridge['error_code'] ) ) {
					$state['error_code'] = (string) $bridge['error_code'];
				}
				if ( 0 === $state['failures'] && isset( $bridge['failures'] ) ) {
					$state['failures'] = (int) $bridge['failures'];
				}
			}

			if ( file_exists( $this->get_parked_path() ) ) {
				$state['open'] = true;
			}

			// A parked-sibling-only open state (bridge cleaned or never
			// written) carries no timestamp of its own; fall back to the
			// parked file's mtime so per-trip dismissal can still match it.
			if ( $state['open'] && 0 === $state['tripped_at'] && file_exists( $this->get_parked_path() ) ) {
				$mtime = filemtime( $this->get_parked_path() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filemtime
				if ( false !== $mtime && $mtime > 0 ) {
					$state['tripped_at'] = (int) $mtime;
				}
			}

			if ( 0 === $state['failures'] ) {
				$failures = $this->read_json_state_file( $this->get_failures_path() );
				if ( is_array( $failures ) && isset( $failures['count'] ) ) {
					$state['failures'] = (int) $failures['count'];
				}
			}

			// Transient mirror of the drop-in counter (written by
			// auto_disable_circuit()): last-resort failures source when the
			// JSON counter file is absent.
			if ( 0 === $state['failures'] ) {
				$mirror = get_transient( Util::transient_key( self::FAIL_TRANSIENT ) );
				if ( is_array( $mirror ) && isset( $mirror['count'] ) ) {
					$state['failures'] = (int) $mirror['count'];
				}
			}

			return $state;
		}

		/**
		 * Trip the circuit from fully-booted WordPress: park the drop-in.
		 *
		 * Plugin-side twin of the drop-in's trip_circuit_breaker() for
		 * WP-context trips (programmatic callers, future WP-CLI wiring). The
		 * drop-in itself trips at early boot; this method renames the drop-in
		 * to object-cache.php.wppo-disabled (marker-guarded — foreign
		 * drop-ins are never touched), writes the disabled-state bridge file,
		 * mirrors state into CIRCUIT_OPTION, arms the admin-notice transient
		 * and the FAIL_TRANSIENT failure mirror (both blog-prefixed via
		 * Util::transient_key() for multisite isolation), and logs the event.
		 *
		 * @since 2.0.0
		 * @param string $reason Human-readable trip reason.
		 * @return bool|\WP_Error True on success, WP_Error for foreign drop-ins or filesystem failures.
		 */
		public function auto_disable_circuit( string $reason = '' ) {
			// Foreign check via the local marker read (no Redis round-trip:
			// a trip path must never pay a connection timeout to decide).
			if ( file_exists( $this->dropin_path ) && ! $this->is_own_dropin() ) {
				return new \WP_Error( 'foreign_dropin', __( 'A foreign drop-in exists. We will not park it for safety.', 'performance-optimisation' ) );
			}

			$failures = 0;
			$counter  = $this->read_json_state_file( $this->get_failures_path() );
			if ( is_array( $counter ) && isset( $counter['count'] ) ) {
				$failures = (int) $counter['count'];
			}

			$tripped_at = time();
			$reason     = '' !== $reason ? substr( sanitize_text_field( $reason ), 0, 200 ) : __( 'Repeated Redis connection failures.', 'performance-optimisation' );

			$wp_filesystem = Util::init_filesystem();
			if ( ! $wp_filesystem ) {
				return new \WP_Error( 'write_error', __( 'Unable to initialize filesystem.', 'performance-optimisation' ) );
			}

			// Park our own drop-in; a foreign file must never be renamed.
			if ( file_exists( $this->dropin_path ) ) {
				if ( ! $this->is_own_dropin() ) {
					return new \WP_Error( 'foreign_dropin', __( 'A foreign drop-in exists. We will not park it for safety.', 'performance-optimisation' ) );
				}
				if ( ! $wp_filesystem->move( $this->dropin_path, $this->get_parked_path(), true ) ) {
					return new \WP_Error( 'write_error', __( 'Cannot park object-cache.php drop-in.', 'performance-optimisation' ) );
				}
			}

			$payload = array(
				'reason'     => $reason,
				'error_code' => 'manual_trip',
				'tripped_at' => $tripped_at,
				'failures'   => $failures,
			);
			$wp_filesystem->put_contents( $this->get_disabled_state_path(), (string) wp_json_encode( $payload ), FS_CHMOD_FILE );

			update_option(
				self::CIRCUIT_OPTION,
				array(
					'open'       => true,
					'tripped_at' => $tripped_at,
					'reason'     => $reason,
					'error_code' => 'manual_trip',
					'failures'   => $failures,
				),
				false
			);
			set_transient(
				Util::transient_key( self::CIRCUIT_NOTICE_TRANSIENT ),
				array(
					'tripped_at' => $tripped_at,
					'reason'     => $reason,
				),
				WEEK_IN_SECONDS
			);
			// Mirror the failure count for admin UI/cron readers that must
			// not touch the filesystem (see FAIL_TRANSIENT).
			set_transient(
				Util::transient_key( self::FAIL_TRANSIENT ),
				array(
					'count'   => $failures,
					'updated' => $tripped_at,
				),
				DAY_IN_SECONDS
			);

			if ( is_callable( array( 'PerformanceOptimise\Inc\System_Info', 'flush_dropin_cache' ) ) ) {
				System_Info::flush_dropin_cache();
			}

			Log::add( __( 'Object Cache circuit breaker tripped — drop-in auto-disabled after repeated Redis failures.', 'performance-optimisation' ) );

			// Arm the recovery probe immediately; Cron::schedule_cron_jobs()
			// keeps it scheduled while the circuit stays open. The
			// 'wppo_object_cache_probe' recurrence slug (interval filterable
			// via wppo_object_cache_probe_interval) is registered by
			// Cron::add_custom_cron_interval().
			if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && ! wp_next_scheduled( 'wppo_object_cache_probe' ) ) {
				wp_schedule_event( time(), 'wppo_object_cache_probe', 'wppo_object_cache_probe' );
			}

			return true;
		}

		/**
		 * Probe Redis and close the circuit when it recovers.
		 *
		 * Lightweight ping(get_redis_config()); on success the drop-in is
		 * restored from the template via enable(), all circuit state is
		 * cleared, the probe schedule is removed, and the recovery is logged.
		 * On failure the circuit stays open (the WP_Error is returned so cron
		 * and REST callers can distinguish "still down" from success).
		 *
		 * @since 2.0.0
		 * @return bool|\WP_Error True when the circuit is closed (or was never open), WP_Error while Redis is still unreachable.
		 */
		public function probe_recovery() {
			$state = $this->get_circuit_state();
			if ( ! $state['open'] ) {
				return true;
			}

			$config = $this->get_redis_config();
			$ping   = $this->ping( $config );
			if ( is_wp_error( $ping ) ) {
				return $ping;
			}

			$result = $this->enable( $config );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$this->clear_circuit_state();

			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( 'wppo_object_cache_probe' );
			}

			Log::add( __( 'Object Cache circuit breaker recovered — drop-in re-enabled after successful Redis probe.', 'performance-optimisation' ) );

			return true;
		}

		/**
		 * Clear every circuit-breaker artefact: option, notice transient,
		 * failure counter, disabled-state bridge, and parked drop-in sibling.
		 *
		 * Called after successful enable()/disable()/probe recovery so a
		 * healed setup never shows a stale "auto-disabled" notice.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function clear_circuit_state(): void {
			delete_option( self::CIRCUIT_OPTION );
			delete_transient( Util::transient_key( self::FAIL_TRANSIENT ) );
			delete_transient( Util::transient_key( self::CIRCUIT_NOTICE_TRANSIENT ) );
			delete_transient( Util::transient_key( self::LAST_FAILURE_TRANSIENT ) );

			$wp_filesystem = Util::init_filesystem();
			foreach ( array( $this->get_parked_path(), $this->get_disabled_state_path(), $this->get_failures_path() ) as $path ) {
				if ( ! file_exists( $path ) ) {
					continue;
				}
				if ( $wp_filesystem ) {
					$wp_filesystem->delete( $path );
				} else {
					@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Stale state files are best-effort cleanup.
				}
			}
		}

		/**
		 * Check whether a drop-in file content belongs to this plugin.
		 *
		 * Standalone helper for uninstall/deactivation contexts that have raw
		 * file contents without a booted Object_Cache instance.
		 *
		 * The legacy marker ('Redis Object Cache Drop-in') is a strict
		 * substring of DROPIN_MARKER and can appear in third-party drop-ins,
		 * so a legacy-only match additionally requires the WPPO-specific
		 * 'wppo-redis-config' signal present in every plugin drop-in.
		 *
		 * @since 2.0.0
		 * @param mixed $content Raw file contents.
		 * @return bool True when the content carries this plugin's marker.
		 */
		public static function is_own_dropin_content( $content ): bool {
			if ( ! is_string( $content ) ) {
				return false;
			}

			if ( false !== strpos( $content, self::DROPIN_MARKER ) ) {
				return true;
			}

			return false !== strpos( $content, self::LEGACY_DROPIN_MARKER ) && false !== strpos( $content, 'wppo-redis-config' );
		}

		/**
		 * Whether the installed drop-in carries this plugin's marker.
		 *
		 * @since 2.0.0
		 * @return bool True when the drop-in is ours (or absent), false for foreign files.
		 */
		private function is_own_dropin(): bool {
			if ( ! file_exists( $this->dropin_path ) ) {
				return true;
			}

			if ( ! is_readable( $this->dropin_path ) ) {
				return false;
			}

			$size = filesize( $this->dropin_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize
			if ( false === $size || $size >= 1048576 ) {
				return false;
			}

			$wp_filesystem = Util::init_filesystem();
			if ( $wp_filesystem ) {
				$content = $wp_filesystem->get_contents( $this->dropin_path );
			} else {
				$content = file_get_contents( $this->dropin_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}

			if ( ! is_string( $content ) ) {
				return false;
			}

			return self::is_own_dropin_content( $content );
		}

		/**
		 * Read a small JSON state file from wp-content.
		 *
		 * @since 2.0.0
		 * @param string $path Absolute file path.
		 * @return array|null Decoded array, or null when missing/unreadable/invalid.
		 */
		private function read_json_state_file( string $path ) {
			if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
				return null;
			}

			$size = filesize( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize
			if ( false === $size || $size < 2 || $size > 65536 ) {
				return null;
			}

			$wp_filesystem = Util::init_filesystem();
			if ( $wp_filesystem ) {
				$raw = $wp_filesystem->get_contents( $path );
			} else {
				$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}

			if ( ! is_string( $raw ) || '' === $raw ) {
				return null;
			}

			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : null;
		}

		/**
		 * Absolute path of the parked drop-in sibling.
		 *
		 * @since 2.0.0
		 * @return string
		 */
		private function get_parked_path(): string {
			return $this->dropin_path . self::PARKED_SUFFIX;
		}

		/**
		 * Absolute path of the disabled-state bridge file.
		 *
		 * @since 2.0.0
		 * @return string
		 */
		private function get_disabled_state_path(): string {
			if ( ! defined( 'WP_CONTENT_DIR' ) ) {
				return '';
			}
			return WP_CONTENT_DIR . '/' . self::DISABLED_STATE_FILE;
		}

		/**
		 * Absolute path of the drop-in failure-counter file.
		 *
		 * @since 2.0.0
		 * @return string
		 */
		private function get_failures_path(): string {
			if ( ! defined( 'WP_CONTENT_DIR' ) ) {
				return '';
			}
			return WP_CONTENT_DIR . '/' . self::FAILURES_FILE;
		}

		/**
		 * Internal helper to connect to Redis based on config.
		 *
		 * @since 1.4.0
		 * @param array $config Configuration array.
		 * @return \Redis|\RedisCluster|\WP_Error
		 */
		private function connect_internal( $config ) {
			if ( ! self::ensure_redis_helper( 'wppo_redis_connect' ) ) {
				return new \WP_Error( 'missing_helper', __( 'The Redis connection helper is unavailable.', 'performance-optimisation' ) );
			}
			return wppo_redis_connect( $config );
		}

		/**
		 * Ensure the Redis connection helper file is loaded and a helper
		 * function from it is available.
		 *
		 * Shared guard for connect_internal(), enable() and
		 * get_serializer_support(): a missing WPPO_PLUGIN_PATH constant or
		 * an unreadable/absent helper file returns false instead of
		 * fataling on a bare require_once.
		 *
		 * @since NEXT
		 * @param string $helper_function Helper function name that must exist after loading.
		 * @return bool True when the helper function is available.
		 */
		private static function ensure_redis_helper( string $helper_function ): bool {
			if ( function_exists( $helper_function ) ) {
				return true;
			}
			$helper = defined( 'WPPO_PLUGIN_PATH' ) ? WPPO_PLUGIN_PATH . 'includes/redis-connect-helper.php' : '';
			if ( '' !== $helper && is_readable( $helper ) ) {
				require_once $helper;
			}
			return function_exists( $helper_function );
		}

		/**
		 * Race-tolerant filesize(): clears the stat cache and suppresses the
		 * TOCTOU warning when the file vanishes between checks.
		 *
		 * @since NEXT
		 * @param string $path File path.
		 * @return int|false Size in bytes or false.
		 */
		private static function safe_filesize( string $path ) {
			if ( function_exists( 'clearstatcache' ) ) {
				clearstatcache( true, $path );
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- TOCTOU: file may vanish between is_readable() and filesize().
			$size = @filesize( $path );
			return $size;
		}

		/**
		 * Get the merged Redis configuration with filter.
		 *
		 * Merges Dashboard settings with on-disk config and allows filtering
		 * via `wppo_object_cache_config` before connection.
		 *
		 * @since 2.0.0
		 * @return array The Redis configuration.
		 */
		public function get_redis_config(): array {
			$options = Util::get_settings();
			$config  = isset( $options['object_cache'] ) ? $options['object_cache'] : array();

			if ( empty( $config ) && file_exists( $this->config_path ) ) {
				// Containment + size guard before include: the config path lives
				// under wp-content but must never escape it via symlink, and an
				// unexpectedly large file is rejected instead of executed.
				// Both sides are realpath()ed so a symlinked wp-content does
				// not false-reject a legitimate config.
				$config_real   = realpath( $this->config_path );
				$content_real  = realpath( WP_CONTENT_DIR );
				$content_dir   = is_string( $content_real ) ? wp_normalize_path( $content_real ) : wp_normalize_path( WP_CONTENT_DIR );
				$config_normal = is_string( $config_real ) ? wp_normalize_path( $config_real ) : '';
				$config_size   = ( '' !== $config_normal && 0 === strpos( $config_normal, $content_dir . '/' ) && is_readable( $this->config_path ) ) ? self::safe_filesize( $this->config_path ) : false;
				if ( false !== $config_size && $config_size > 0 && $config_size <= 65536 ) {
					$config = include $this->config_path; // phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound
				}
				if ( ! is_array( $config ) ) {
					$config = array();
				}
			}

			/**
			 * Filter the Redis object cache configuration.
			 *
			 * @since 2.0.0
			 * @param array $config The Redis configuration.
			 */
			$config = (array) apply_filters( 'wppo_object_cache_config', $config );

			return $config;
		}

		/**
		 * Ping the Redis server to test connection.
		 *
		 * @since 1.4.0
		 * @param array $config Connection configuration.
		 * @return bool|\WP_Error True if connected, WP_Error on failure.
		 */
		public function ping( $config = array() ) {
			// Defense-in-depth: ping() accepts caller-supplied host/port/nodes
			// (SSRF/port-scan primitive if ever wired without caps), so it
			// self-enforces the same gate as enable()/disable().
			if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) && ! ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return new \WP_Error( 'forbidden', __( 'You are not allowed to manage the object cache.', 'performance-optimisation' ) );
			}
			if ( ! class_exists( 'Redis' ) ) {
				return new \WP_Error( 'missing_extension', __( 'The PhpRedis extension is not installed.', 'performance-optimisation' ) );
			}

			/**
			 * Filter the Redis object cache configuration.
			 *
			 * @since 2.0.0
			 * @param array $config The Redis configuration.
			 */
			$config = (array) apply_filters( 'wppo_object_cache_config', $config );

			$connection = $this->connect_internal( $config );

			if ( is_wp_error( $connection ) ) {
				$this->log_redis_failure( $connection->get_error_code(), $connection->get_error_message() );
				return $connection;
			}

			if ( method_exists( $connection, 'ping' ) ) {
				try {
					$result = $connection->ping();
					$connection->close();

					if ( true === $result || '+PONG' === $result || ( is_string( $result ) && stripos( $result, 'PONG' ) !== false ) ) {
						return true;
					}
					$error = new \WP_Error( 'ping_fail', __( 'Ping returned false', 'performance-optimisation' ) );
					$this->log_redis_failure( $error->get_error_code(), $error->get_error_message() );
					return $error;
				} catch ( \Exception $e ) {
					if ( method_exists( $connection, 'close' ) ) {
						$connection->close();
					}
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( 'Redis ping exception: ' . str_replace( ABSPATH, '', $e->getMessage() ) );
					}
					$error = new \WP_Error( 'ping_exception', __( 'Redis connection failed.', 'performance-optimisation' ) );
					$this->log_redis_failure( $error->get_error_code(), $error->get_error_message() );
					return $error;
				}
			}

			$connection->close();
			$error = new \WP_Error( 'no_ping_method', __( 'Connection does not support ping', 'performance-optimisation' ) );
			$this->log_redis_failure( $error->get_error_code(), $error->get_error_message() );
			return $error;
		}


		/**
		 * Whether rendered Redis config source looks structurally valid.
		 *
		 * Pure-PHP shape check used to verify staged config before it is
		 * renamed over the live path: the source must be a small PHP file
		 * returning an array. Deliberately never `include`s the candidate —
		 * including a torn file is the very fatal being avoided, and the
		 * ABSPATH guard inside the config makes include-based verification
		 * fragile outside a booted WordPress request.
		 *
		 * @since NEXT
		 * @param mixed $contents Candidate config source.
		 * @return bool True when the source has the expected config shape.
		 */
		public static function is_valid_config_content( $contents ): bool {
			if ( ! is_string( $contents ) || '' === $contents ) {
				return false;
			}
			$length = strlen( $contents );
			if ( $length < 16 || $length > 65536 ) {
				return false;
			}
			if ( 0 !== strpos( ltrim( $contents ), '<?php' ) ) {
				return false;
			}
			if ( false === strpos( $contents, 'return' ) || false === strpos( $contents, 'array' ) ) {
				return false;
			}
			$trimmed = rtrim( $contents );
			return '' !== $trimmed && ';' === substr( $trimmed, -1 );
		}

		/**
		 * Publish Redis config source atomically via tmp-write + verify + rename.
		 *
		 * Stages `$content` at a unique tmp sibling (falling back to the
		 * `CONFIG_TMP_SUFFIX` sibling), re-reads it byte-identically,
		 * syntax-checks it, then renames it over the live config path
		 * (atomic on the same filesystem for the Direct transport; FTP/SSH
		 * transports re-read and re-verify the live file after the move and
		 * restore the previous bytes on mismatch, so they still never leave
		 * a torn live file behind).
		 *
		 * On any atomic-step failure the old live config is left untouched and
		 * a `WP_Error` is returned (fail-closed file, fail-open site: the
		 * object cache stays on the previous config or disabled, never fatal).
		 *
		 * Multisite-safe by construction: the config path is
		 * installation-wide, no per-site options are read or written here.
		 *
		 * @since NEXT
		 * @param string $content       Rendered config PHP source.
		 * @param mixed  $wp_filesystem Filesystem object from `Util::init_filesystem()`.
		 * @return bool|\WP_Error True on verified publish, WP_Error on any failure.
		 */
		private function write_config_atomic( string $content, $wp_filesystem ) {
			if ( ! is_object( $wp_filesystem ) ) {
				return new \WP_Error( 'write_error', __( 'Cannot write Redis configuration file.', 'performance-optimisation' ) );
			}

			if ( ! self::is_valid_config_content( $content ) ) {
				return new \WP_Error( 'write_error', __( 'Cannot write Redis configuration file.', 'performance-optimisation' ) );
			}

			// Preferred path: the shared verified writer (tmp + byte-identical
			// re-read + PHP syntax check + backup + rename + post-rename
			// re-verify with restore). Guarded so a partially-updated Util
			// class can never fatal this call.
			if ( method_exists( 'PerformanceOptimise\Inc\Util', 'atomic_write_php_verified' ) ) {
				try {
					$atomic = Util::atomic_write_php_verified( $wp_filesystem, $this->config_path, $content, array( self::class, 'is_valid_config_content' ) );
				} catch ( \Throwable $e ) {
					unset( $e );
					$atomic = false;
				}
				if ( true === $atomic ) {
					$this->sweep_orphan_config_tmp( $wp_filesystem );
					return true;
				}
				if ( false === $atomic ) {
					return new \WP_Error( 'write_error', __( 'Cannot write Redis configuration file.', 'performance-optimisation' ) );
				}
				// Null: the transport lacks methods for the verified writer —
				// fall through to the manual tmp + verify + rename below.
			}

			// Manual fallback needing only put_contents()/get_contents() plus
			// a rename primitive; every other filesystem call is best-effort.
			try {
				if ( ! method_exists( $wp_filesystem, 'put_contents' ) || ! method_exists( $wp_filesystem, 'get_contents' ) ) {
					return new \WP_Error( 'write_error', __( 'Cannot write Redis configuration file.', 'performance-optimisation' ) );
				}

				// Unique staging sibling so concurrent enable() calls never share
				// a tmp path and spuriously fail each other's verification.
				$tmp = $this->config_path . self::CONFIG_TMP_SUFFIX;
				if ( method_exists( 'PerformanceOptimise\Inc\Util', 'atomic_tmp_path' ) ) {
					try {
						$uniq = Util::atomic_tmp_path( $this->config_path );
					} catch ( \Throwable $e ) {
						unset( $e );
						$uniq = '';
					}
					if ( is_string( $uniq ) && '' !== $uniq ) {
						$tmp = $uniq;
					}
				}
				$chmod = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;

				// In-memory original for post-rename restore on non-atomic
				// (FTP/SSH copy+delete) transports.
				$original     = '';
				$had_original = false;
				try {
					if ( method_exists( $wp_filesystem, 'exists' ) ) {
						if ( $wp_filesystem->exists( $this->config_path ) ) {
							$read = $wp_filesystem->get_contents( $this->config_path );
							if ( is_string( $read ) && '' !== $read ) {
								$original     = $read;
								$had_original = true;
							}
						}
					} else {
						$read = $wp_filesystem->get_contents( $this->config_path );
						if ( is_string( $read ) && '' !== $read ) {
							$original     = $read;
							$had_original = true;
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}

				if ( ! $wp_filesystem->put_contents( $tmp, $content, $chmod ) ) {
					$this->delete_config_tmp_quietly( $wp_filesystem, $tmp );
					return new \WP_Error( 'write_error', __( 'Cannot write Redis configuration file.', 'performance-optimisation' ) );
				}

				$staged = $wp_filesystem->get_contents( $tmp );
				if ( ! is_string( $staged ) || $staged !== $content || ! self::is_valid_config_content( $staged ) ) {
					$this->delete_config_tmp_quietly( $wp_filesystem, $tmp );
					return new \WP_Error( 'write_error', __( 'Cannot write Redis configuration file.', 'performance-optimisation' ) );
				}

				if ( method_exists( 'PerformanceOptimise\Inc\Util', 'verify_php_syntax' ) && ! Util::verify_php_syntax( $staged ) ) {
					$this->delete_config_tmp_quietly( $wp_filesystem, $tmp );
					return new \WP_Error( 'write_error', __( 'Cannot write Redis configuration file.', 'performance-optimisation' ) );
				}

				// Best-effort single backup of the previous live config before
				// publish, so a torn live file detected below has a restore
				// source even when the in-memory read was unavailable. Swept on
				// success; never blocks publish.
				try {
					if ( $had_original && method_exists( $wp_filesystem, 'copy' ) ) {
						$wp_filesystem->copy( $this->config_path, $this->config_path . '.wppo-bak', true );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}

				$moved = false;
				if ( method_exists( $wp_filesystem, 'move' ) ) {
					$moved = $wp_filesystem->move( $tmp, $this->config_path, true );
				} elseif ( function_exists( 'rename' ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- Fallback only when the filesystem transport exposes no move(); the tmp sibling is cleaned on failure.
					$moved = @rename( $tmp, $this->config_path );
				}

				if ( ! $moved ) {
					$this->delete_config_tmp_quietly( $wp_filesystem, $tmp );
					return new \WP_Error( 'write_error', __( 'Cannot write Redis configuration file.', 'performance-optimisation' ) );
				}

				// Post-rename re-read + re-verify: on FTP/SSH transports
				// move() is copy+delete (non-atomic), so a kill mid-copy can
				// still leave a torn live file. On mismatch restore the
				// in-memory original (or the best-effort backup) and fail
				// closed instead of leaving a fatal config behind.
				try {
					$written = $wp_filesystem->get_contents( $this->config_path );
				} catch ( \Throwable $e ) {
					unset( $e );
					$written = false;
				}
				$live_ok = is_string( $written ) && $written === $content && self::is_valid_config_content( $written );
				if ( $live_ok && method_exists( 'PerformanceOptimise\Inc\Util', 'verify_php_syntax' ) ) {
					try {
						$live_ok = Util::verify_php_syntax( $written );
					} catch ( \Throwable $e ) {
						unset( $e );
						$live_ok = false;
					}
				}
				if ( ! $live_ok ) {
					try {
						if ( $had_original && method_exists( $wp_filesystem, 'put_contents' ) ) {
							$wp_filesystem->put_contents( $this->config_path, $original, $chmod );
						} elseif ( method_exists( $wp_filesystem, 'exists' ) && method_exists( $wp_filesystem, 'copy' ) && $wp_filesystem->exists( $this->config_path . '.wppo-bak' ) ) {
							$wp_filesystem->copy( $this->config_path . '.wppo-bak', $this->config_path, true );
						} elseif ( ! $had_original && method_exists( $wp_filesystem, 'delete' ) ) {
							$wp_filesystem->delete( $this->config_path );
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
					$this->delete_config_tmp_quietly( $wp_filesystem, $tmp );
					return new \WP_Error( 'write_error', __( 'Cannot write Redis configuration file.', 'performance-optimisation' ) );
				}

				$this->sweep_orphan_config_tmp( $wp_filesystem );
				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				try {
					if ( isset( $tmp ) && is_string( $tmp ) ) {
						$this->delete_config_tmp_quietly( $wp_filesystem, $tmp );
					}
				} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort tmp cleanup must never throw.
				}
				return new \WP_Error( 'write_error', __( 'Cannot write Redis configuration file.', 'performance-optimisation' ) );
			}
		}

		/**
		 * Best-effort delete of a staging tmp path; never throws.
		 *
		 * @since NEXT
		 * @param mixed  $wp_filesystem Filesystem object.
		 * @param string $path          Tmp path to remove.
		 * @return void
		 */
		private function delete_config_tmp_quietly( $wp_filesystem, string $path ): void {
			try {
				if ( '' !== $path && is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'delete' ) ) {
					$wp_filesystem->delete( $path );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Sweep orphan config staging files left by an interrupted write.
		 *
		 * Removes the fixed `CONFIG_TMP_SUFFIX` sibling plus unique
		 * `*.tmp.*` siblings from killed verified-writes, as well as a
		 * stale `.wppo-bak` backup left behind when a run is killed between
		 * backup creation and post-success cleanup. Best-effort only
		 * and never throws; a sweep racing a concurrent writer merely fails
		 * that writer's verification (fail-closed), never corrupts the live
		 * config.
		 *
		 * @since NEXT
		 * @param mixed $wp_filesystem Filesystem object.
		 * @return void
		 */
		private function sweep_orphan_config_tmp( $wp_filesystem ): void {
			try {
				if ( ! is_object( $wp_filesystem ) ) {
					return;
				}

				$tmp = $this->config_path . self::CONFIG_TMP_SUFFIX;
				if ( method_exists( $wp_filesystem, 'exists' ) ) {
					try {
						if ( $wp_filesystem->exists( $tmp ) ) {
							$this->delete_config_tmp_quietly( $wp_filesystem, $tmp );
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				} else {
					$this->delete_config_tmp_quietly( $wp_filesystem, $tmp );
				}

				// Stale backup from a run killed between backup creation
				// and post-success cleanup: the shared writer removes it on
				// success, so anything left here is orphaned.
				$backup = $this->config_path . '.wppo-bak';
				if ( method_exists( $wp_filesystem, 'exists' ) ) {
					try {
						if ( $wp_filesystem->exists( $backup ) ) {
							$this->delete_config_tmp_quietly( $wp_filesystem, $backup );
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				} else {
					$this->delete_config_tmp_quietly( $wp_filesystem, $backup );
				}

				if ( ! method_exists( $wp_filesystem, 'dirlist' ) || ! function_exists( 'dirname' ) || ! function_exists( 'basename' ) ) {
					return;
				}

				$dir    = dirname( $this->config_path );
				$prefix = basename( $this->config_path ) . '.tmp.';
				try {
					$list = $wp_filesystem->dirlist( $dir );
				} catch ( \Throwable $e ) {
					unset( $e );
					return;
				}
				if ( ! is_array( $list ) ) {
					return;
				}
				foreach ( $list as $name => $info ) {
					$entry = is_string( $name ) ? $name : '';
					if ( '' === $entry && is_array( $info ) && isset( $info['name'] ) && is_string( $info['name'] ) ) {
						$entry = $info['name'];
					}
					if ( '' !== $entry && 0 === strpos( $entry, $prefix ) ) {
						$this->delete_config_tmp_quietly( $wp_filesystem, $dir . '/' . $entry );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}


		/**
		 * Install the Redis object-cache drop-in by writing the plugin config and copying the drop-in into place.
		 *
		 * May return a WP_Error for conditions such as missing PHP Redis extension, presence of a foreign drop-in,
		 * or failures writing or copying files.
		 *
		 * @since 1.4.0
		 * @param array $config Connection configuration used to generate the Redis config file.
		 * @return bool|\WP_Error `true` on success, `WP_Error` on failure (possible error codes: `missing_extension`, `foreign_dropin`, `write_error`).
		 */
		public function enable( $config ) {
			// Defense-in-depth: authorization lives in REST/CLI callers, but a
			// direct PHP call must not get privileged file writes.
			if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return new \WP_Error( 'forbidden', __( 'You are not allowed to manage the object cache.', 'performance-optimisation' ) );
			}
			if ( ! class_exists( 'Redis' ) ) {
				return new \WP_Error( 'missing_extension', __( 'The PhpRedis extension is not installed.', 'performance-optimisation' ) );
			}

			/** This filter is documented in get_redis_config(). */
			$config = (array) apply_filters( 'wppo_object_cache_config', $config );

			$status = $this->get_status();
			if ( $status['foreign_dropin'] ) {
				return new \WP_Error( 'foreign_dropin', __( 'Another Object Cache drop-in is already present. Please disable it before enabling this one.', 'performance-optimisation' ) );
			}

			// Test connection before writing config.
			$ping_result = $this->ping( $config );
			if ( is_wp_error( $ping_result ) ) {
				return new \WP_Error( 'redis_unreachable', __( 'Cannot connect to Redis with provided settings.', 'performance-optimisation' ) );
			}

			// Format nodes as array for the config file if it's a string.
			if ( ! empty( $config['nodes'] ) ) {
				if ( ! self::ensure_redis_helper( 'wppo_parse_nodes' ) ) {
					return new \WP_Error( 'missing_helper', __( 'The Redis connection helper is unavailable.', 'performance-optimisation' ) );
				}
				$config['nodes'] = wppo_parse_nodes( $config['nodes'] );
			}

			$config_data = $config;

			// Remove password from config file — sensitive credentials are stored
			// in the `wppo_settings` option (database) and merged at load time via
			// the `WPPO_REDIS_PASSWORD` constant, environment variable, or the
			// object cache's REST handler.
			unset( $config_data['password'] );

			// Also remove passwords from replica entries to prevent credential leakage.
			if ( isset( $config_data['replicas'] ) && is_array( $config_data['replicas'] ) ) {
				foreach ( $config_data['replicas'] as $idx => $replica ) {
					if ( is_array( $replica ) && isset( $replica['password'] ) ) {
						unset( $config_data['replicas'][ $idx ]['password'] );
					}
				}
			}

			// Write config file using var_export for clean array representation.
			$config_content = "<?php\n/**\n * Auto-generated by Performance Optimisation\n */\n\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n\n";
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			$config_content .= 'return ' . var_export( $config_data, true ) . ";\n";

			$wp_filesystem = Util::init_filesystem();

			if ( ! $wp_filesystem ) {
				return new \WP_Error( 'write_error', __( 'Unable to initialize filesystem.', 'performance-optimisation' ) );
			}

			// Crash-safe publish: stage to a tmp sibling, verify, then rename —
			// a process killed mid-write can never leave a half-written live
			// config that would fatal the object-cache drop-in (bare include)
			// on the next request. On any atomic-step failure the old config
			// is left untouched (fail-closed file, fail-open site).
			$published = $this->write_config_atomic( $config_content, $wp_filesystem );
			if ( is_wp_error( $published ) ) {
				return $published;
			}

			// Defense-in-depth: the config file lives under web-reachable
			// wp-content and only has an ABSPATH guard. Drop deny rules for
			// it (Apache + OLS .htaccess, IIS web.config) so topology is
			// not disclosed when PHP handling is disabled.
			self::protect_config_file();

			// Copy drop-in.
			if ( ! $wp_filesystem->copy( $this->template_path, $this->dropin_path, true, FS_CHMOD_FILE ) ) {
				$wp_filesystem->delete( $this->config_path );
				return new \WP_Error( 'write_error', __( 'Cannot copy object-cache.php drop-in.', 'performance-optimisation' ) );
			}

			// The drop-in file changed — invalidate the memoized ownership
			// verdict so a later flush_scoped() on this instance re-checks.
			$this->own_dropin_memo = null;

			// The drop-in changed — System Info's cached ownership verdict is
			// stale (audit #888 finding 25). is_callable also covers a partially
			// loaded class (Part 2 review round 2).
			if ( is_callable( array( 'PerformanceOptimise\Inc\System_Info', 'flush_dropin_cache' ) ) ) {
				System_Info::flush_dropin_cache();
			}

			// A successful enable closes the circuit: drop the parked
			// sibling, the disabled-state bridge, the failure counter, and
			// the circuit option/notice so no stale "auto-disabled" UI lingers.
			$this->clear_circuit_state();

			// Optionally, ping cache flush if enabled just to clear old cruft.
			wp_cache_flush();

			return true;
		}


		/**
		 * Disable the object cache by deleting the drop-in file.
		 *
		 * @since 1.4.0
		 * @return bool|\WP_Error True on success, WP_Error on failure.
		 */
		public function disable() {
			if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return new \WP_Error( 'forbidden', __( 'You are not allowed to manage the object cache.', 'performance-optimisation' ) );
			}
			$status = $this->get_status();
			if ( $status['foreign_dropin'] ) {
				return new \WP_Error( 'foreign_dropin', __( 'A foreign drop-in exists. We will not delete it for safety.', 'performance-optimisation' ) );
			}

			$wp_filesystem = Util::init_filesystem();

			if ( ! $wp_filesystem ) {
				return new \WP_Error( 'delete_error', __( 'Unable to initialize filesystem.', 'performance-optimisation' ) );
			}

			if ( file_exists( $this->dropin_path ) ) {
				if ( ! $wp_filesystem->delete( $this->dropin_path ) ) {
					return new \WP_Error( 'delete_error', __( 'Cannot delete object-cache.php drop-in.', 'performance-optimisation' ) );
				}
				// The drop-in file changed — invalidate the memoized ownership
				// verdict so a later flush_scoped() on this instance re-checks.
				$this->own_dropin_memo = null;
			}

			if ( file_exists( $this->config_path ) ) {
				$wp_filesystem->delete( $this->config_path );
			}

			// A deliberate manual disable resolves any parked circuit state
			// too, so a stale "auto-disabled" notice never outlives it.
			$this->clear_circuit_state();

			// The drop-in changed — System Info's cached ownership verdict is
			// stale (audit #888 finding 25). is_callable also covers a partially
			// loaded class (Part 2 review round 2).
			if ( is_callable( array( 'PerformanceOptimise\Inc\System_Info', 'flush_dropin_cache' ) ) ) {
				System_Info::flush_dropin_cache();
			}

			return true;
		}

		/**
		 * Write Apache/LiteSpeed deny rules shielding the redis config file.
		 *
		 * The config file lives under the web-reachable wp-content tree and
		 * only carries an ABSPATH guard, so a server that stops handing .php
		 * to PHP would serve it as plain text and disclose the Redis
		 * topology. Adds a `<Files>` deny block to wp-content/.htaccess and
		 * creates a minimal wp-content/web.config deny (only when absent, so
		 * an existing site config is never clobbered).
		 *
		 * Best-effort only; failures never block enable(). Apache and
		 * OpenLiteSpeed both read .htaccess; nginx ignores both files, so
		 * nginx deployments must deny the file at the server level with:
		 * `location = /wp-content/wppo-redis-config.php { deny all; }`.
		 *
		 * @since NEXT
		 * @return void
		 */
		private static function protect_config_file(): void {
			try {
				if ( function_exists( 'insert_with_markers' ) ) {
					$htaccess = wp_normalize_path( (string) WP_CONTENT_DIR ) . '/.htaccess';
					// Both authz generations are emitted behind IfModule guards:
					// a bare `Require all denied` is Apache 2.4-only syntax and a
					// server without mod_authz_core answers 500 for the whole
					// wp-content tree rather than ignoring the directive.
					$rule = array(
						'<Files "wppo-redis-config.php">',
						'<IfModule mod_authz_core.c>',
						'Require all denied',
						'</IfModule>',
						'<IfModule !mod_authz_core.c>',
						'Order allow,deny',
						'Deny from all',
						'</IfModule>',
						'</Files>',
					);
					insert_with_markers( $htaccess, self::CONFIG_HTACCESS_MARKER, $rule );
				}
				// IIS: a web.config deny for the config file (created only when
				// absent so an existing site config is never clobbered). Nginx
				// has no directory-level config — see the server-level rule
				// documented on this method.
				$web_config = wp_normalize_path( (string) WP_CONTENT_DIR ) . '/web.config';
				$fs         = Util::init_filesystem();
				if ( $fs && ! $fs->exists( $web_config ) ) {
					$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
					$xml .= "<configuration>\n";
					$xml .= "  <location path=\"wppo-redis-config.php\">\n";
					$xml .= "    <system.webServer>\n";
					$xml .= "      <security>\n";
					$xml .= "        <authorization>\n";
					$xml .= "          <deny users=\"*\" />\n";
					$xml .= "        </authorization>\n";
					$xml .= "      </security>\n";
					$xml .= "    </system.webServer>\n";
					$xml .= "  </location>\n";
					$xml .= "</configuration>\n";
					$fs->put_contents( $web_config, $xml, FS_CHMOD_FILE );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Transient key (blog-prefixed via Util::transient_key()) carrying the
		 * latest Redis failure for admin-notice / REST surfacing.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		public const LAST_FAILURE_TRANSIENT = 'wppo_redis_last_failure';

		/**
		 * Record a Redis failure in-app (activity log + admin-notice transient).
		 *
		 * Drop-in early-boot failures can only reach error_log(); this is the
		 * fully-booted twin that writes where admins actually look. The
		 * error_log line is kept as a secondary sink under WP_DEBUG. Never
		 * throws — logging must not break the fail-open path.
		 *
		 * @since 2.0.0
		 * @param string $code    Machine-readable failure code.
		 * @param string $message Human-readable failure description.
		 * @return void
		 */
		public function log_redis_failure( string $code, string $message ): void {
			// Pure-PHP sanitization (no sanitize_key/sanitize_text_field):
			// Brain Monkey's function_exists() returns true for WP stubs
			// defined by earlier tests in the same process, but calling an
			// unstubbed function throws MissingFunctionExpectations — so the
			// logging path must never depend on WP sanitizers.
			$code    = strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $code ) );
			$message = trim( (string) preg_replace( '/<[^>]*>/', '', (string) $message ) );
			$code    = substr( $code, 0, 64 );
			$message = substr( $message, 0, 200 );
			if ( '' === $code ) {
				$code = 'redis_error';
			}
			if ( '' === $message ) {
				$message = __( 'Redis connection failed.', 'performance-optimisation' );
			}

			try {
				Log::add( sprintf( 'Redis failure (%s): %s', $code, $message ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			try {
				set_transient(
					Util::transient_key( self::LAST_FAILURE_TRANSIENT ),
					array(
						'code'    => $code,
						'message' => $message,
						'time'    => time(),
					),
					DAY_IN_SECONDS
				);
				// NOTE: the circuit-notice transient is armed only by the
				// breaker trip path (auto_disable_circuit()); plain failures
				// must not clobber the trip payload/TTL (WEEK vs DAY), and the
				// latest failure is surfaced via get_status()['last_failure'].
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WPPO Redis failure (' . $code . '): ' . $message );
			}
		}

		/**
		 * Report which serializers the current phpredis build can safely use.
		 *
		 * Mirrors the wppo_resolve_redis_serializer() probe (igbinary only
		 * when the extension is present, otherwise PHP) without touching a
		 * connection, for the REST status payload and SPA capability display.
		 * The `msgpack` key only reports ext-msgpack availability; msgpack is
		 * never auto-selected because doing so would make existing
		 * SERIALIZER_PHP entries unreadable on upgrade.
		 *
		 * @since 2.0.0
		 * @return array Shape { active: string, igbinary: bool, msgpack: bool, php: bool }.
		 */
		public function get_serializer_support(): array {
			$support = array(
				'active'   => 'php',
				'igbinary' => false,
				'msgpack'  => false,
				'php'      => true,
			);

			try {
				if ( ! self::ensure_redis_helper( 'wppo_resolve_redis_serializer' ) ) {
					return $support;
				}
				if ( function_exists( 'wppo_resolve_redis_serializer' ) ) {
					$resolved          = wppo_resolve_redis_serializer();
					$support['active'] = isset( $resolved['name'] ) ? (string) $resolved['name'] : 'php';
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			try {
				$support['igbinary'] = defined( '\Redis::SERIALIZER_IGBINARY' )
					&& ( extension_loaded( 'igbinary' ) || function_exists( 'igbinary_serialize' ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			try {
				$version_ok         = version_compare( (string) phpversion( 'redis' ), '5.0.0', '>=' );
				$support['msgpack'] = $version_ok
					&& defined( '\Redis::SERIALIZER_MSGPACK' )
					&& ( extension_loaded( 'msgpack' ) || function_exists( 'msgpack_serialize' ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			return $support;
		}

		/**
		 * Last flush failure (set by flush(), cleared on success).
		 *
		 * Lets the REST flush handler forward the manager's real failure
		 * code/message instead of synthesizing a generic one, without
		 * changing the bool flush() contract relied on by WP-CLI/abilities.
		 *
		 * @since 2.0.0
		 * @var \WP_Error|null
		 */
		private $last_flush_error = null;

		/**
		 * Memoized is_own_dropin() verdict for flush_scoped() retries.
		 *
		 * Avoids repeating the file_exists + filesystem + up-to-1MB read
		 * on every retry within the same manager instance. Reset to null
		 * whenever the drop-in file changes (see enable()/disable()) so a
		 * stale verdict can never bypass the foreign-drop-in refusal or
		 * wrongly refuse after enable().
		 *
		 * @since NEXT
		 * @var bool|null Null when not yet computed.
		 */
		private $own_dropin_memo = null;

		/**
		 * Read the latest recorded Redis failure for admin/REST surfacing.
		 *
		 * Fail-open: returns null when the transient is missing, malformed,
		 * or unreadable — callers treat null as "no known failure".
		 *
		 * @since 2.0.0
		 * @return array|null Shape { code: string, message: string, time: int } or null.
		 */
		public function get_last_failure_payload() {
			try {
				$payload = get_transient( Util::transient_key( self::LAST_FAILURE_TRANSIENT ) );
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
			if ( ! is_array( $payload ) || empty( $payload['code'] ) ) {
				return null;
			}
			return array(
				'code'    => (string) $payload['code'],
				'message' => isset( $payload['message'] ) ? (string) $payload['message'] : '',
				'time'    => isset( $payload['time'] ) ? (int) $payload['time'] : 0,
			);
		}

		/**
		 * Latest flush failure, if any.
		 *
		 * @since 2.0.0
		 * @return \WP_Error|null The WP_Error set by the last failed flush(), or null.
		 */
		public function get_last_flush_error() {
			return $this->last_flush_error instanceof \WP_Error ? $this->last_flush_error : null;
		}

		/**
		 * Flush the complete object cache with best-effort memory-delta logging.
		 *
		 * Runs wp_cache_flush() (the drop-in performs the namespace-aware
		 * SCAN+DEL sweep with its own full-keyspace verification + retry).
		 * No plugin-side re-scan is done here: re-scanning over a fresh
		 * connection races with concurrent writers repopulating between the
		 * drop-in sweep and this check (flaky false negatives) and costs an
		 * extra connection per flush. Memory growth after a flush is logged,
		 * never failed. Failures are recorded in-app via
		 * log_redis_failure() and exposed via get_last_flush_error() so REST
		 * can surface the real code/message.
		 *
		 * @since 1.4.0
		 * @since 2.0.0 Removed plugin-side re-verification (drop-in verifies with retry); failures exposed via get_last_flush_error().
		 * @return bool True when flushed, false otherwise.
		 */
		public function flush() {
			// Defense-in-depth: network/file-cache action, so self-enforce
			// the same gate as enable()/disable() instead of relying solely
			// on caller gating (REST/CLI already require manage_options).
			if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) && ! ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				$this->last_flush_error = new \WP_Error( 'forbidden', __( 'You are not allowed to manage the object cache.', 'performance-optimisation' ) );
				return false;
			}
			if ( ! function_exists( 'wp_cache_flush' ) ) {
				$this->last_flush_error = new \WP_Error( 'flush_unavailable', __( 'Object cache flush is unavailable.', 'performance-optimisation' ) );
				return false;
			}

			$this->last_flush_error = null;
			$memory_before          = $this->read_redis_memory_bytes();
			$flushed                = wp_cache_flush();
			if ( ! $flushed ) {
				$this->last_flush_error = new \WP_Error( 'flush_fail', __( 'Object cache flush reported failure.', 'performance-optimisation' ) );
				$this->log_redis_failure( 'flush_fail', __( 'Object cache flush reported failure.', 'performance-optimisation' ) );
				return false;
			}

			$memory_after = $this->read_redis_memory_bytes();
			if ( null !== $memory_before && null !== $memory_after && $memory_after > $memory_before ) {
				// Memory growth after a flush means writers repopulated (or
				// another blog shares the DB) — not a flush failure, so
				// still report success but log the delta for diagnosability.
				try {
					Log::add(
						sprintf(
							/* translators: %1$d: memory before flush in bytes, %2$d: memory after flush in bytes */
							__( 'Object cache flushed with memory delta: %1$d → %2$d bytes.', 'performance-optimisation' ),
							$memory_before,
							$memory_after
						)
					);
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			return true;
		}

		/**
		 * Flush the object cache scoped to the current blog on multisite.
		 *
		 * The WPPO drop-in's flush() already sweeps only the current blog
		 * prefix (see templates/object-cache.php), but a foreign or absent
		 * drop-in may expose a global FLUSHDB behind wp_cache_flush(). On
		 * multisite this method therefore:
		 *
		 * - refuses to flush while a foreign object-cache.php drop-in is
		 *   active (fail closed with a WP_Error instead of risking
		 *   sibling-site data);
		 * - temporarily forces the `object_cache_allow_flush_all` opt-out
		 *   filter to false so the WPPO drop-in takes its blog-prefix
		 *   SCAN+DEL path even when something opted into a full flush.
		 *
		 * On single-site installs this delegates straight to flush().
		 *
		 * Fail-open trade-off: when the multisite state cannot be determined
		 * (is_multisite() undefined or throwing) this method treats the
		 * install as single-site and delegates to flush(). That keeps an
		 * admin-initiated flush working on a partially-booted stack, at the
		 * cost of bypassing the foreign-drop-in refusal below; the decision
		 * is logged via log_redis_failure() so it stays diagnosable.
		 *
		 * Capability: this method self-enforces the manage_options gate
		 * (with wp_doing_cron()/WP_CLI exemptions so CLI and cron stay
		 * usable), mirroring enable()/disable()/flush().
		 *
		 * On failure sets last_flush_error (see get_last_flush_error()).
		 *
		 * @since NEXT
		 * @return bool True when the scoped flush succeeded, false otherwise.
		 */
		public function flush_scoped(): bool {
			if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) && ! ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				$this->last_flush_error = new \WP_Error( 'forbidden', __( 'You are not allowed to manage the object cache.', 'performance-optimisation' ) );
				return false;
			}
			$multisite_unknown = false;
			$is_multisite      = false;
			if ( function_exists( 'is_multisite' ) ) {
				try {
					$is_multisite = (bool) is_multisite();
				} catch ( \Throwable $e ) {
					unset( $e );
					$multisite_unknown = true;
					$is_multisite      = false;
				}
			} else {
				$multisite_unknown = true;
			}

			if ( ! $is_multisite ) {
				if ( $multisite_unknown ) {
					$this->log_redis_failure( 'flush_multisite_unknown', 'Multisite state unknown; scoped flush fell back to single-site flush().' );
				}
				return $this->flush();
			}

			$blog_id = 0;
			if ( function_exists( 'get_current_blog_id' ) ) {
				try {
					$blog_id = (int) get_current_blog_id();
				} catch ( \Throwable $e ) {
					unset( $e );
					$blog_id = 0;
				}
			}

			try {
				if ( null === $this->own_dropin_memo ) {
					$this->own_dropin_memo = $this->is_own_dropin();
				}
				$own_dropin = $this->own_dropin_memo;
			} catch ( \Throwable $e ) {
				unset( $e );
				$own_dropin = false;
			}

			if ( ! $own_dropin ) {
				$this->last_flush_error = new \WP_Error(
					'flush_foreign_dropin',
					sprintf(
					/* translators: %d: current blog ID */
						__( 'Object cache flush refused on site %d: a foreign object-cache.php drop-in is active, so a scoped flush cannot be guaranteed.', 'performance-optimisation' ),
						$blog_id
					)
				);
				$this->log_redis_failure( 'flush_foreign_dropin', sprintf( 'Scoped flush refused for blog %d: foreign object-cache drop-in active.', $blog_id ) );
				return false;
			}

			$force_scoped = null;
			if ( function_exists( 'add_filter' ) && function_exists( 'remove_filter' ) ) {
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Filter signature requires the param.
				$force_scoped = static function ( $allow ) {
					return false;
				};
				add_filter( 'object_cache_allow_flush_all', $force_scoped, PHP_INT_MAX );
			}

			try {
				return $this->flush();
			} catch ( \Throwable $e ) {
				$this->last_flush_error = new \WP_Error( 'flush_exception', __( 'Object cache flush failed.', 'performance-optimisation' ) );
				$this->log_redis_failure( 'flush_exception', $e->getMessage() );
				return false;
			} finally {
				if ( null !== $force_scoped ) {
					try {
						remove_filter( 'object_cache_allow_flush_all', $force_scoped, PHP_INT_MAX );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			}
		}

		/**
		 * Read Redis used_memory in bytes via INFO (best-effort).
		 *
		 * @since 2.0.0
		 * @return int|null Bytes used, or null when unreachable/unavailable.
		 */
		private function read_redis_memory_bytes() {
			try {
				$config     = $this->get_redis_config();
				$connection = $this->connect_internal( $config );
				if ( is_wp_error( $connection ) ) {
					return null;
				}
				try {
					if ( ! method_exists( $connection, 'info' ) ) {
						return null;
					}
					$info = $connection->info( 'memory' );
					if ( is_array( $info ) && isset( $info['used_memory'] ) ) {
						return (int) $info['used_memory'];
					}
					if ( is_array( $info ) ) {
						// Cluster mode returns per-node info maps: sum
						// used_memory across all nodes.
						$total = 0;
						$found = false;
						foreach ( $info as $node_info ) {
							if ( is_array( $node_info ) && isset( $node_info['used_memory'] ) ) {
								$total += (int) $node_info['used_memory'];
								$found  = true;
							}
						}
						if ( $found ) {
							return $total;
						}
					}
				} finally {
					if ( method_exists( $connection, 'close' ) ) {
						try {
							$connection->close();
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return null;
		}
	}
}
