<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Redis Object Cache Drop-in for Performance Optimisation
 *
 * NOTE: this file loads before the plugin (and before Util) — it must never
 * call Util::get_settings() or read the wppo_settings option. Redis config
 * comes only from WP_CONTENT_DIR . '/wppo-redis-config.php'.
 * See tests/php/SettingsReadGuardTest.php.
 *
 * @package PerformanceOptimise
 * @since 1.4.0
 */

/**
 * Object Cache Drop-in for WordPress.
 */
if ( ! class_exists( 'WP_Object_Cache' ) ) {
	/**
	 * WP_Object_Cache class.
	 *
	 * @since 1.4.0
	 *
	 * phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed
	 */
	class WP_Object_Cache {
		/**
		 * Holds the cache data.
		 *
		 * @var array
		 */
		private $cache = array();

		/**
		 * Holds the Redis client instance.
		 *
		 * @var \Redis|\RedisCluster|null
		 */
		private $redis;

		/**
		 * Holds the Redis client replica instance.
		 *
		 * @var \Redis|null
		 */
		private $redis_replica = null;

		/**
		 * Flag indicating if Redis is connected.
		 *
		 * @var bool
		 */
		private $redis_connected = false;

		/**
		 * Prefix for the blog namespace.
		 *
		 * @var string
		 */
		public $blog_prefix;

		/**
		 * Salt for cache key prefixing (WP 6.9+).
		 *
		 * @var string
		 */
		private $salt = '';

		/**
		 * Constructor.
		 */
		public function __construct() {
			global $table_prefix;

			$this->blog_prefix = ( is_multisite() ? get_current_blog_id() : $table_prefix ) . ':';

			$this->connect_redis();
		}

		/**
		 * Adds salt to the cache key prefix.
		 *
		 * Called by wp_cache_add_salt() (WP 6.9+) to invalidate all cached data
		 * by changing the key space.
		 *
		 * @since NEXT
		 *
		 * @param string $salt The salt string to add.
		 * @return void
		 */
		public function add_salt( $salt ) {
			$this->salt = $salt;
		}

		/**
		 * Initializes and connects the object cache to Redis using the configuration file.
		 *
		 * Reads WP_CONTENT_DIR . '/wppo-redis-config.php' (expects an array). If a valid
		 * config is present, attempts to connect a primary Redis client and, when
		 * configured for standalone mode with replicas, attempts to establish a replica
		 * connection. On success assigns the client(s) to $this->redis and
		 * $this->redis_replica (when available) and sets $this->redis_connected to
		 * true; on failure leaves or sets $this->redis_connected to false and clears
		 * any replica.
		 */
		private function connect_redis() {
			$config_file = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' ) . '/wppo-redis-config.php';
			$config      = array();

			if ( file_exists( $config_file ) ) {
				$config = include $config_file; // phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound
			}

			if ( ! is_array( $config ) ) {
				return;
			}

			$use_tls      = $config['use_tls'] ?? false;
			$database     = isset( $config['database'] ) ? (int) $config['database'] : 0;
			$env_password = getenv( 'WPPO_REDIS_PASSWORD' );
			$password     = defined( 'WPPO_REDIS_PASSWORD' ) ? WPPO_REDIS_PASSWORD : ( false !== $env_password ? $env_password : ( $config['password'] ?? '' ) );
			$timeout      = 0.5;

			$config['password'] = $password;

			if ( ! function_exists( 'wppo_redis_connect' ) ) {
				/*
				 * WP_PLUGIN_DIR is only defined by wp_plugin_directory_constants()
				 * AFTER the object cache boots on WP 6.x/7.x (see wp-settings.php),
				 * so referencing it here fatals on every request. Derive the plugins
				 * directory from WP_CONTENT_DIR when the constant is not yet
				 * available, mirroring core's default. Custom plugin directories
				 * defined in wp-config.php are already defined by this point.
				 */
				$plugins_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( ( defined( 'WP_CONTENT_DIR' ) ? rtrim( WP_CONTENT_DIR, '/\\' ) : '' ) . '/plugins' );
				$helper_file = $plugins_dir . '/performance-optimisation/includes/redis-connect-helper.php';
				if ( ! file_exists( $helper_file ) ) {
					// Fallback: the plugin directory may have been renamed
					// (e.g. mu-plugins installs or custom slugs). Glob every
					// plugin's helper and prefer a directory matching the
					// performance-optimisation slug fragment.
					$candidates = glob( $plugins_dir . '/*/includes/redis-connect-helper.php' );
					if ( is_array( $candidates ) ) {
						foreach ( $candidates as $candidate ) {
							if ( false !== strpos( $candidate, 'performance-optimisation' ) ) {
								$helper_file = $candidate;
								break;
							}
						}
					}
				}
				if ( file_exists( $helper_file ) ) {
					require_once $helper_file;
				}
			}

			if ( ! function_exists( 'wppo_redis_connect' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WPPO Redis object cache drop-in: wppo_redis_connect() not found — helper may be missing.' );
				$this->redis_connected = false;
				return;
			}

			try {
				$connection = wppo_redis_connect( $config );

				if ( is_wp_error( $connection ) ) {
					$this->redis_connected = false;
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					$this->log_redis_failure_once( 'WPPO Redis object cache: connection failed — ' . $connection->get_error_message() . ' Serving from memory.' );
					$error_code = $connection->get_error_code();
					$this->record_redis_failure( is_string( $error_code ) && '' !== $error_code ? $error_code : 'unknown', $connection->get_error_message() );
					return;
				}

				$this->redis           = $connection;
				$this->redis_connected = true;
				$this->log_redis_recovery();

				// Standalone replica support.
				if ( 'standalone' === ( $config['mode'] ?? 'standalone' )
					&& ! empty( $config['replicas'] )
					&& is_array( $config['replicas'] )
				) {
					$replica_key = array_rand( $config['replicas'] );
					$replica     = $config['replicas'][ $replica_key ];

					$r_host = $replica['host'] ?? '127.0.0.1';
					$r_port = isset( $replica['port'] ) ? (int) $replica['port'] : 6379;
					$r_pass = $replica['password'] ?? $password;
					try {
						$tmp_replica = new \Redis();
						if ( $use_tls && strpos( $r_host, 'tls://' ) !== 0 ) {
							$r_host = 'tls://' . $r_host;
						}
						if ( $tmp_replica->connect( $r_host, $r_port, $timeout ) ) {
							$replica_auth_ok = true;
							if ( ! empty( $r_pass ) ) {
								$replica_auth_ok = $tmp_replica->auth( $r_pass );
							}

							if ( $replica_auth_ok && $tmp_replica->select( $database ) ) {
								$this->redis_replica = $tmp_replica;
							} else {
								$tmp_replica->close();
							}
						}
					} catch ( \Throwable $e ) {
						$this->redis_replica = null;
					}
				}

				if ( $this->redis_connected && $this->redis ) {
					if ( function_exists( 'wppo_apply_redis_options' ) ) {
						wppo_apply_redis_options( $this->redis, $config );
						if ( $this->redis_replica ) {
							wppo_apply_redis_options( $this->redis_replica, $config );
						}
					}
				}
			} catch ( \Throwable $e ) {
				$this->redis_connected = false;
				$this->redis_replica   = null;
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				$this->log_redis_failure_once( 'WPPO Redis object cache: boot connection error — ' . $e->getMessage() . ' Serving from memory.' );
				$this->record_redis_failure( 'boot_exception', $e->getMessage() );
			}
		}

		/**
		 * Log a Redis failure at most once per 5 minutes (and once per request).
		 *
		 * Object-cache drop-ins boot on every request, so raw error_log() would
		 * flood the log during an outage. A flag file in WP_CONTENT_DIR stores
		 * the last-log time because the (failing) cache itself cannot be used
		 * for throttling.
		 *
		 * @param string $message Failure description.
		 * @return void
		 */
		private function log_redis_failure_once( string $message ): void {
			static $logged_this_request = false;
			if ( $logged_this_request ) {
				return;
			}

			if ( ! defined( 'WP_CONTENT_DIR' ) ) {
				$logged_this_request = true;
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( $message );
				return;
			}

			$flag_file = WP_CONTENT_DIR . '/wppo-redis-down.flag';
			$now       = time();
			$last      = @filemtime( $flag_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $last || ( $now - $last ) >= 300 ) {
				$logged_this_request = true;
				// Touch only when logging: refreshing the mtime on every
				// request would keep ( $now - $last ) below the threshold and
				// silence all but the first outage log line.
				@touch( $flag_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_touch
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( $message );
			}
		}

		/**
		 * Log Redis recovery when a previously failed connection succeeds again.
		 *
		 * @return void
		 */
		private function log_redis_recovery(): void {
			if ( ! defined( 'WP_CONTENT_DIR' ) ) {
				return;
			}

			// A healthy boot resets the circuit-breaker failure window so a
			// past outage cannot trip the breaker long after recovery.
			$this->clear_redis_failures();

			$flag_file = WP_CONTENT_DIR . '/wppo-redis-down.flag';
			if ( @file_exists( $flag_file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $flag_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WPPO Redis object cache: connection recovered.' );
			}
		}

		/**
		 * WP_Error codes from wppo_redis_connect() that must NOT count toward
		 * the circuit breaker.
		 *
		 * These describe environment/configuration problems (missing PHP
		 * classes, no nodes configured, old phpredis) rather than a Redis
		 * outage, so tripping the breaker on them would park a drop-in that
		 * no recovery probe could ever heal.
		 *
		 * @since NEXT
		 * @var string[]
		 */
		private const NON_CIRCUIT_ERROR_CODES = array( 'missing_redis', 'missing_cluster', 'missing_sentinel', 'low_nodes', 'redis_version' );

		/**
		 * Resolve the circuit-breaker failure threshold.
		 *
		 * Readable without WordPress: the WPPO_CB_THRESHOLD constant wins,
		 * then the wppo_object_cache_circuit_breaker_threshold filter (when
		 * plugins are loaded), defaulting to 5 consecutive failures.
		 *
		 * @since NEXT
		 * @return int Minimum 1.
		 */
		private function get_circuit_threshold(): int {
			$threshold = defined( 'WPPO_CB_THRESHOLD' ) ? (int) WPPO_CB_THRESHOLD : 5;
			if ( function_exists( 'apply_filters' ) ) {
				/**
				 * Filter the object-cache circuit-breaker failure threshold.
				 *
				 * @since NEXT
				 * @param int $threshold Consecutive counted failures that trip the breaker. Default 5.
				 */
				$threshold = (int) apply_filters( 'wppo_object_cache_circuit_breaker_threshold', $threshold );
			}
			return max( 1, $threshold );
		}

		/**
		 * Resolve the circuit-breaker counting window in seconds.
		 *
		 * Readable without WordPress: the WPPO_CB_WINDOW constant wins, then
		 * the wppo_object_cache_circuit_breaker_window filter (when plugins
		 * are loaded), defaulting to 600 (10 minutes). Failures older than
		 * the window reset the counter instead of tripping the breaker.
		 *
		 * @since NEXT
		 * @return int Minimum 1.
		 */
		private function get_circuit_window(): int {
			$window = defined( 'WPPO_CB_WINDOW' ) ? (int) WPPO_CB_WINDOW : 600;
			if ( function_exists( 'apply_filters' ) ) {
				/**
				 * Filter the object-cache circuit-breaker counting window.
				 *
				 * @since NEXT
				 * @param int $window Seconds in which threshold failures must occur to trip. Default 600.
				 */
				$window = (int) apply_filters( 'wppo_object_cache_circuit_breaker_window', $window );
			}
			return max( 1, $window );
		}

		/**
		 * Record one counted Redis failure and trip the breaker at threshold.
		 *
		 * The counter lives in WP_CONTENT_DIR/wppo-redis-failures.json as
		 * { count, first, last } and is updated under an exclusive flock()
		 * because the drop-in boots on every request — transients and the
		 * options API are unavailable (and would recurse into this very
		 * cache) at this boot stage. Only auth/connection-class errors count;
		 * environment/config codes (see NON_CIRCUIT_ERROR_CODES) are ignored.
		 * When the breaker already tripped (parked sibling on disk) counting
		 * stops so the state file is written exactly once.
		 *
		 * @since NEXT
		 * @param string $error_code Machine-readable failure code.
		 * @param string $reason     Human-readable failure description.
		 * @return void
		 */
		private function record_redis_failure( string $error_code, string $reason ): void {
			if ( ! defined( 'WP_CONTENT_DIR' ) ) {
				return;
			}

			if ( in_array( $error_code, self::NON_CIRCUIT_ERROR_CODES, true ) ) {
				return;
			}

			$content_dir   = WP_CONTENT_DIR;
			$failures_file = $content_dir . '/wppo-redis-failures.json';

			// Already tripped: the parked sibling is the open state. Skip
			// counting so a restored-then-failing drop-in cannot double-trip
			// and so the one final trip log line stays final.
			if ( @file_exists( $content_dir . '/object-cache.php.wppo-disabled' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return;
			}

			$threshold = $this->get_circuit_threshold();
			$window    = $this->get_circuit_window();
			$now       = time();

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged
			$handle = @fopen( $failures_file, 'c+' );
			if ( ! $handle ) {
				return;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
			if ( ! flock( $handle, LOCK_EX ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $handle );
				return;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind
			rewind( $handle );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents
			$raw   = stream_get_contents( $handle );
			$state = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
			if ( ! is_array( $state ) ) {
				$state = array();
			}

			$count = isset( $state['count'] ) ? (int) $state['count'] : 0;
			$first = isset( $state['first'] ) ? (int) $state['first'] : 0;

			// Failures outside the window start a fresh counting period.
			if ( $count < 1 || $first < 1 || ( $now - $first ) > $window ) {
				$count = 0;
				$first = $now;
			}

			++$count;
			$state = array(
				'count' => $count,
				'first' => $first,
				'last'  => $now,
			);

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftruncate
			ftruncate( $handle, 0 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind
			rewind( $handle );
			// wp_json_encode() may not exist yet at drop-in boot; json_encode() is the early-boot fallback.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			$encoded_state = function_exists( 'wp_json_encode' ) ? wp_json_encode( $state ) : json_encode( $state );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $handle, (string) $encoded_state );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
			flock( $handle, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );

			if ( $count >= $threshold ) {
				$this->trip_circuit_breaker( $error_code, $reason, $state );
			}
		}

		/**
		 * Clear the circuit-breaker failure counter.
		 *
		 * Called on every healthy boot (via log_redis_recovery()) so past
		 * outages cannot trip the breaker after recovery.
		 *
		 * @since NEXT
		 * @return void
		 */
		private function clear_redis_failures(): void {
			if ( ! defined( 'WP_CONTENT_DIR' ) ) {
				return;
			}

			$failures_file = WP_CONTENT_DIR . '/wppo-redis-failures.json';
			if ( @file_exists( $failures_file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $failures_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}

		/**
		 * Trip the circuit breaker: park this drop-in so failing Redis stops
		 * costing a connection timeout on every request.
		 *
		 * Renames WP_CONTENT_DIR/object-cache.php to
		 * object-cache.php.wppo-disabled ONLY when the file carries this
		 * plugin's marker — foreign drop-ins are never touched — and writes
		 * WP_CONTENT_DIR/wppo-redis-disabled.json ({ reason, error_code,
		 * tripped_at, failures }) as the bridge the plugin side
		 * (Object_Cache::get_circuit_state()) reads for the admin notice and
		 * the recovery probe. Logs exactly one final line; the tripping
		 * request itself keeps serving from the in-memory fallback.
		 *
		 * @since NEXT
		 * @param string $error_code Machine-readable failure code.
		 * @param string $reason     Human-readable failure description.
		 * @param array  $state      Counter state { count, first, last }.
		 * @return void
		 */
		private function trip_circuit_breaker( string $error_code, string $reason, array $state ): void {
			if ( ! defined( 'WP_CONTENT_DIR' ) ) {
				return;
			}

			$content_dir = WP_CONTENT_DIR;
			$dropin      = $content_dir . '/object-cache.php';
			$parked      = $dropin . '.wppo-disabled';
			$state_file  = $content_dir . '/wppo-redis-disabled.json';

			// Run the trip body exactly once per outage: either artefact
			// proves a trip already happened. Without the bridge guard, a
			// failed rename (permissions, race) would re-trip and error_log
			// on every subsequent failing request instead of logging once.
			if ( @file_exists( $parked ) || @file_exists( $state_file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return;
			}

			// Never touch a foreign drop-in: parking is destructive, so it
			// requires the narrow plugin-specific marker. (The shorter
			// legacy phrase can appear in foreign drop-ins and stays valid
			// only for read-only ownership detection elsewhere.)
			$is_ours = false;
			if ( @is_readable( $dropin ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize
				$size = @filesize( $dropin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false !== $size && $size < 1048576 ) {
					// The drop-in boots before WP_Filesystem exists; local file reads are the only option.
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged
					$content = @file_get_contents( $dropin );
					if ( is_string( $content ) && false !== strpos( $content, 'Redis Object Cache Drop-in for Performance Optimisation' ) ) {
						$is_ours = true;
					}
				}
			}

			// wp_strip_all_tags() may not exist yet at drop-in boot; strip_tags() is the early-boot fallback.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
			$clean_reason = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $reason ) : strip_tags( $reason );
			$payload      = array(
				'reason'     => substr( (string) $clean_reason, 0, 200 ),
				'error_code' => substr( (string) $error_code, 0, 64 ),
				'tripped_at' => time(),
				'failures'   => isset( $state['count'] ) ? (int) $state['count'] : 0,
			);

			// wp_json_encode() may not exist yet at drop-in boot; json_encode() is the early-boot fallback.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );
			if ( is_string( $encoded ) && '' !== $encoded ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged
				@file_put_contents( $state_file, $encoded, LOCK_EX );
			}

			// Keep the down-flag touched so the throttled outage logging stays
			// quiet now that the breaker owns the failure state.
			@touch( $content_dir . '/wppo-redis-down.flag' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_touch

			if ( $is_ours ) {
				// The drop-in boots before WP_Filesystem exists; rename() is the only parking primitive.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged
				$renamed = @rename( $dropin, $parked );
				if ( $renamed ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO Redis object cache: circuit breaker tripped after ' . (int) $payload['failures'] . ' failures (' . $payload['error_code'] . ') — drop-in auto-disabled to ' . basename( $parked ) . '. Re-enable from Performance → Object Cache once Redis recovers.' );
					return;
				}
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'WPPO Redis object cache: circuit breaker tripped after ' . (int) $payload['failures'] . ' failures (' . $payload['error_code'] . ') — drop-in left in place (rename skipped or failed). Serving from memory.' );
		}

		/**
		 * Retrieves the actual key prefixed correctly.
		 *
		 * @param string $key   Cache key.
		 * @param string $group Cache group.
		 * @return string Prefix cache key.
		 */
		private function get_key( $key, $group = '' ) {
			$group = empty( $group ) ? 'default' : $group;

			if ( in_array( $group, $this->global_groups, true ) ) {
				$prefix = '';
			} else {
				$prefix = $this->blog_prefix;
			}

			return $prefix . $this->salt . $group . ':' . $key;
		}

		/**
		 * Adds data to the cache if it doesn't already exist.
		 *
		 * @param int|string $key    Cache key.
		 * @param mixed      $data   Cache data.
		 * @param string     $group  Cache group.
		 * @param int        $expire Expiration.
		 * @return bool True on success.
		 */
		public function add( $key, $data, $group = 'default', $expire = 0 ) {
			if ( wp_suspend_cache_addition() ) {
				return false;
			}

			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				$local_key = $this->get_key( $key, $group );
				if ( isset( $this->cache[ $local_key ] ) ) {
					return false;
				}
				$this->cache[ $local_key ] = $data;
				return true;
			}

			$local_key = $this->get_key( $key, $group );

			if ( $expire ) {
				return $this->redis->set(
					$local_key,
					$data,
					array(
						'nx' => true,
						'ex' => $expire,
					)
				);
			}
			return $this->redis->setnx( $local_key, $data );
		}

		/**
		 * Sets data to the cache.
		 *
		 * @param int|string $key    Cache key.
		 * @param mixed      $data   Cache data.
		 * @param string     $group  Cache group.
		 * @param int        $expire Expiration.
		 * @return bool True on success.
		 */
		public function set( $key, $data, $group = 'default', $expire = 0 ) {
			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				$this->cache[ $this->get_key( $key, $group ) ] = $data;
				return true;
			}

			$formatted_key = $this->get_key( $key, $group );

			try {
				if ( $expire > 0 ) {
					return $this->redis->setex( $formatted_key, $expire, $data );
				}

				return $this->redis->set( $formatted_key, $data );
			} catch ( \Throwable $e ) {
				// First failed write after a healthy connection: degrade to the
				// in-memory store and log once so outages are diagnosable
				// without flooding the log.
				$this->redis_connected = false;
				// Drop the replica handle too: get()/get_multiple() prefer it
				// over the primary, so a stale connected flag plus a dead
				// replica could route reads to a broken connection.
				$this->redis_replica = null;
				$this->log_redis_failure_once( 'WPPO Redis object cache: write failed — ' . $e->getMessage() . ' Dropping to memory until next boot.' );
				$this->record_redis_failure( 'write_fail', $e->getMessage() );
				$this->cache[ $formatted_key ] = $data;
				return true;
			}
		}

		/**
		 * Gets data from the cache.
		 *
		 * @param int|string $key   Cache key.
		 * @param string     $group Cache group.
		 * @param bool       $force Force from Redis.
		 * @param bool       $found Result flag.
		 * @return mixed False if failed.
		 */
		public function get( $key, $group = 'default', $force = false, &$found = null ) {
			$local_key = $this->get_key( $key, $group );

			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				if ( isset( $this->cache[ $local_key ] ) ) {
					$found = true;
					return $this->cache[ $local_key ];
				}
				$found = false;
				return false;
			}

			if ( ! $force && isset( $this->cache[ $local_key ] ) ) {
				$found = true;
				return $this->cache[ $local_key ];
			}

			$redis_instance = $this->redis_replica ? $this->redis_replica : $this->redis;
			$value          = $redis_instance->get( $local_key );

			if ( false === $value ) {
				$found = false;
				return false;
			}

			$found                     = true;
			$this->cache[ $local_key ] = $value;
			return $value;
		}

		/**
		 * Retrieves multiple values from the cache.
		 *
		 * @param array  $keys  Array of keys.
		 * @param string $group Cache group.
		 * @param bool   $force Force from Redis.
		 * @return array Array of return values.
		 */
		public function get_multiple( $keys, $group = 'default', $force = false ) {
			$values = array();
			if ( empty( $keys ) ) {
				return $values;
			}

			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				foreach ( $keys as $key ) {
					$local_key = $this->get_key( $key, $group );
					if ( ! $force && isset( $this->cache[ $local_key ] ) ) {
						$values[ $key ] = $this->cache[ $local_key ];
					} else {
						$values[ $key ] = false;
					}
				}
				return $values;
			}

			// Exclude keys already in local cache if not forcing.
			$keys_to_fetch  = array();
			$formatted_keys = array();
			foreach ( $keys as $key ) {
				$local_key = $this->get_key( $key, $group );
				if ( ! $force && isset( $this->cache[ $local_key ] ) ) {
					$values[ $key ] = $this->cache[ $local_key ];
				} else {
					$keys_to_fetch[]  = $key;
					$formatted_keys[] = $local_key;
				}
			}

			if ( empty( $keys_to_fetch ) ) {
				return $values;
			}

			$redis_instance = $this->redis_replica ? $this->redis_replica : $this->redis;
			$redis_values   = $redis_instance->mGet( $formatted_keys );

			foreach ( $keys_to_fetch as $index => $key ) {
				if ( isset( $redis_values[ $index ] ) && false !== $redis_values[ $index ] ) {
					$local_key                 = $formatted_keys[ $index ];
					$this->cache[ $local_key ] = $redis_values[ $index ];
					$values[ $key ]            = $redis_values[ $index ];
				} else {
					$values[ $key ] = false;
				}
			}

			return $values;
		}

		/**
		 * Sets multiple values to the cache.
		 *
		 * @param array  $data   Array of keys and values.
		 * @param string $group  Cache group.
		 * @param int    $expire Expiration.
		 * @return bool True on success.
		 */
		public function set_multiple( $data, $group = 'default', $expire = 0 ) {
			if ( empty( $data ) ) {
				return array();
			}

			$results        = array();
			$formatted_data = array();
			foreach ( $data as $key => $value ) {
				$local_key                    = $this->get_key( $key, $group );
				$this->cache[ $local_key ]    = $value;
				$formatted_data[ $local_key ] = $value;
			}

			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				foreach ( $data as $key => $value ) {
					$results[ $key ] = true;
				}
				return $results;
			}

			if ( $expire > 0 ) {
				// We must use a pipeline for mSet with expiration.
				$pipeline = $this->redis->multi( \Redis::PIPELINE );
				foreach ( $formatted_data as $k => $v ) {
					$pipeline->setex( $k, $expire, $v );
				}
				$replies = $pipeline->exec();

				if ( ! is_array( $replies ) ) {
					return array_fill_keys( array_keys( $data ), false );
				}

				$i = 0;
				foreach ( $data as $key => $value ) {
					$results[ $key ] = (bool) ( $replies[ $i ] ?? false );
					++$i;
				}
				return $results;
			}

			$ok = $this->redis->mSet( $formatted_data );
			foreach ( $data as $key => $value ) {
				$results[ $key ] = $ok;
			}
			return $results;
		}

		/**
		 * Deletes data from the cache.
		 *
		 * @param int|string $key   Cache key.
		 * @param string     $group Cache group.
		 * @return bool True on success.
		 */
		public function delete( $key, $group = 'default' ) {
			$local_key = $this->get_key( $key, $group );
			unset( $this->cache[ $local_key ] );

			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				return true;
			}

			$this->redis->del( $local_key );
			return true;
		}

		/**
		 * Deletes multiple values from the cache.
		 *
		 * @param array  $keys  Array of keys.
		 * @param string $group Cache group.
		 * @return bool True on success.
		 */
		public function delete_multiple( $keys, $group = 'default' ) {
			if ( empty( $keys ) ) {
				return array();
			}

			$results        = array();
			$formatted_keys = array();
			foreach ( $keys as $key ) {
				$local_key = $this->get_key( $key, $group );
				unset( $this->cache[ $local_key ] );
				$formatted_keys[] = $local_key;
			}

			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				foreach ( $keys as $key ) {
					$results[ $key ] = true;
				}
				return $results;
			}

			// Redis DEL returns count of deleted keys, not per-key success.
			// To match the contract strictly, we could use a pipeline, but standard DEL is more efficient.
			// We'll use a pipeline to get individual results if strict contract is required.
			$pipeline = $this->redis->multi( \Redis::PIPELINE );
			foreach ( $formatted_keys as $k ) {
				$pipeline->del( $k );
			}
			$replies = $pipeline->exec();

			foreach ( $keys as $i => $key ) {
				$results[ $key ] = (bool) ( $replies[ $i ] ?? false );
			}

			return $results;
		}

		/**
		 * Replaces existing data.
		 *
		 * @param int|string $key    Cache key.
		 * @param mixed      $data   Cache data.
		 * @param string     $group  Cache group.
		 * @param int        $expire Expiration.
		 * @return bool True on success.
		 */
		public function replace( $key, $data, $group = 'default', $expire = 0 ) {
			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				return false;
			}

			$formatted_key = $this->get_key( $key, $group );

			if ( ! $this->redis->exists( $formatted_key ) ) {
				return false;
			}

			return $this->set( $key, $data, $group, $expire );
		}

		/**
		 * Flushes the object cache for this site only.
		 *
		 * Uses a SCAN loop to find and delete keys matching this site's prefix,
		 * avoiding a global FLUSH. Operators may opt in to a full flushDb() via
		 * the 'object_cache_allow_flush_all' filter for single-site/isolated setups.
		 *
		 * @return bool True on success.
		 */
		public function flush() {
			$this->cache = array();
			if ( $this->redis_connected ) {
				if ( apply_filters( 'object_cache_allow_flush_all', false ) ) {
					return $this->redis->flushDb();
				}

				$prefix  = $this->blog_prefix;
				$pattern = $prefix . '*';

				if ( $this->redis instanceof \RedisCluster ) {
					$masters = $this->redis->_masters();
					foreach ( $masters as $node ) {
						$cursor = null;
						do {
							$keys = $this->redis->scan( $cursor, $node, $pattern, 100 );
							if ( false === $keys ) {
								break;
							}
							if ( is_array( $keys ) && ! empty( $keys ) ) {
								$this->redis->del( $keys );
							}
						} while ( $cursor && ( is_numeric( $cursor ) && 0 !== (int) $cursor ) );
					}
					return true;
				}

				$cursor = null;
				do {
					$keys = $this->redis->scan( $cursor, $pattern, 100 );
					if ( false === $keys ) {
						break;
					}
					if ( is_array( $keys ) && ! empty( $keys ) ) {
						$this->redis->del( $keys );
					}
				} while ( $cursor && ( is_numeric( $cursor ) && 0 !== (int) $cursor ) );

				return true;
			}
			return true;
		}

		/**
		 * Flushes a specific cache group.
		 *
		 * Uses a SCAN loop to find and delete all keys matching the group's
		 * prefix pattern, leaving other cache groups untouched.
		 *
		 * @param string $group The cache group to flush.
		 * @return bool True on success.
		 */
		public function flush_group( $group ) {
			$group_prefix = $this->get_key( '', $group );
			foreach ( array_keys( $this->cache ) as $local_key ) {
				if ( strpos( $local_key, $group_prefix ) === 0 ) {
					unset( $this->cache[ $local_key ] );
				}
			}
			if ( $this->redis_connected ) {
				$pattern = $group_prefix . '*';

				if ( $this->redis instanceof \RedisCluster ) {
					$masters = $this->redis->_masters();
					foreach ( $masters as $node ) {
						$cursor = null;
						do {
							$keys = $this->redis->scan( $cursor, $node, $pattern, 100 );
							if ( false === $keys ) {
								break;
							}
							if ( is_array( $keys ) && ! empty( $keys ) ) {
								$this->redis->del( $keys );
							}
						} while ( $cursor && ( is_numeric( $cursor ) && 0 !== (int) $cursor ) );
					}
					return true;
				}

				$cursor = null;
				do {
					$keys = $this->redis->scan( $cursor, $pattern, 100 );
					if ( false === $keys ) {
						break;
					}
					if ( is_array( $keys ) && ! empty( $keys ) ) {
						$this->redis->del( $keys );
					}
				} while ( $cursor && ( is_numeric( $cursor ) && 0 !== (int) $cursor ) );

				return true;
			}
			return true;
		}

		/**
		 * Increases a cached value.
		 *
		 * @param int|string $key    Cache key.
		 * @param int        $offset Offset amount.
		 * @param string     $group  Cache group.
		 * @return int|bool The new value or false.
		 */
		public function incr( $key, $offset = 1, $group = 'default' ) {
			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				$local_key = $this->get_key( $key, $group );
				if ( ! isset( $this->cache[ $local_key ] ) ) {
					$this->cache[ $local_key ] = 0;
				}
				$this->cache[ $local_key ] += $offset;
				return $this->cache[ $local_key ];
			}

			return $this->redis->incrBy( $this->get_key( $key, $group ), $offset );
		}

		/**
		 * Decreases a cached value.
		 *
		 * @param int|string $key    Cache key.
		 * @param int        $offset Offset amount.
		 * @param string     $group  Cache group.
		 * @return int|bool The new value or false.
		 */
		public function decr( $key, $offset = 1, $group = 'default' ) {
			if ( in_array( $group, $this->no_mc_groups, true ) || ! $this->redis_connected ) {
				$local_key = $this->get_key( $key, $group );
				if ( ! isset( $this->cache[ $local_key ] ) ) {
					$this->cache[ $local_key ] = 0;
				}
				$this->cache[ $local_key ] -= $offset;
				return $this->cache[ $local_key ];
			}

			return $this->redis->decrBy( $this->get_key( $key, $group ), $offset );
		}

		/**
		 * Sets the list of global groups.
		 *
		 * @param array $groups Global groups.
		 * @return void
		 */
		public function add_global_groups( $groups ) {
			$groups              = (array) $groups;
			$this->global_groups = array_unique( array_merge( $this->global_groups, $groups ) );
		}

		/**
		 * Sets the list of groups that should not be cached in Redis.
		 *
		 * @param array $groups Non-persistent groups.
		 * @return void
		 */
		public function add_non_persistent_groups( $groups ) {
			$groups             = (array) $groups;
			$this->no_mc_groups = array_unique( array_merge( $this->no_mc_groups, $groups ) );
		}

		/**
		 * Magic getter for backward compatibility.
		 *
		 * @param string $name Property name.
		 * @return mixed
		 */
		public function __get( $name ) {
			return $this->$name;
		}

		/**
		 * Non-persistent groups.
		 *
		 * Core keeps these groups in the DB as the source of truth (options,
		 * the cron array among them); persisting them to Redis causes stale
		 * reads after a Redis outage/restart (e.g. "Cron reschedule event
		 * error: could_not_set" floods). They fall back to the per-request
		 * in-memory store, matching core's no-object-cache behaviour.
		 *
		 * @var array
		 */
		private $no_mc_groups = array( 'options', 'alloptions', 'network_options' );

		/**
		 * Global groups.
		 *
		 * @var array
		 */
		private $global_groups = array( 'image_editor' );
	}
}

/**
 * Global cache functions.
 */

/**
 * Adds data to the cache.
 *
 * @param int|string $key    Cache key.
 * @param mixed      $data   Cache data.
 * @param string     $group  Cache group.
 * @param int        $expire Expiration.
 * @return bool True on success.
 */
function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add( $key, $data, $group, (int) $expire );
}

/**
 * Sets data to the cache.
 *
 * @param int|string $key    Cache key.
 * @param mixed      $data   Cache data.
 * @param string     $group  Cache group.
 * @param int        $expire Expiration.
 * @return bool True on success.
 */
function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set( $key, $data, $group, (int) $expire );
}

/**
 * Gets data from the cache.
 *
 * @param int|string $key   Cache key.
 * @param string     $group Cache group.
 * @param bool       $force Force from Redis.
 * @param bool       $found Result flag.
 * @return mixed False if failed.
 */
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	global $wp_object_cache;
	return $wp_object_cache->get( $key, $group, $force, $found );
}

/**
 * Deletes data from the cache.
 *
 * @param int|string $key   Cache key.
 * @param string     $group Cache group.
 * @return bool True on success.
 */
function wp_cache_delete( $key, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->delete( $key, $group );
}

/**
 * Flushes the object cache.
 */
function wp_cache_flush() {
	global $wp_object_cache;
	return $wp_object_cache->flush();
}

/**
 * Flushes a specific cache group.
 *
 * @param string $group The cache group to flush.
 * @return bool True on success.
 */
function wp_cache_flush_group( $group ) {
	global $wp_object_cache;
	return $wp_object_cache->flush_group( $group );
}

/**
 * Initializes the object cache.
 */
function wp_cache_init() {
	global $wp_object_cache;
	// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
	$wp_object_cache = new WP_Object_Cache();
}

/**
 * Replaces existing data.
 *
 * @param int|string $key    Cache key.
 * @param mixed      $data   Cache data.
 * @param string     $group  Cache group.
 * @param int        $expire Expiration.
 * @return bool True on success.
 */
function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->replace( $key, $data, $group, (int) $expire );
}

/**
 * Adds global groups.
 *
 * @param array $groups Global groups.
 */
function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_global_groups( $groups );
}

/**
 * Adds non-persistent groups.
 *
 * @param array $groups Non-persistent groups.
 */
function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_non_persistent_groups( $groups );
}

/**
 * Increases a cached value.
 *
 * @param int|string $key    Cache key.
 * @param int        $offset Offset amount.
 * @param string     $group  Cache group.
 * @return int|bool The new value or false.
 */
function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->incr( $key, $offset, $group );
}

/**
 * Decreases a cached value.
 *
 * @param int|string $key    Cache key.
 * @param int        $offset Offset amount.
 * @param string     $group  Cache group.
 * @return int|bool The new value or false.
 */
function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->decr( $key, $offset, $group );
}

/**
 * Closes the cache connection on shutdown.
 *
 * Called by WordPress core's shutdown_action_hook() in wp-includes/load.php.
 * Must exist in every object-cache drop-in or WordPress throws a fatal error
 * on shutdown: "Call to undefined function wp_cache_close()".
 *
 * @return bool Always true.
 */
function wp_cache_close() {
	global $wp_object_cache;
	if ( isset( $wp_object_cache ) && $wp_object_cache instanceof WP_Object_Cache ) {
		if ( $wp_object_cache->redis_connected && $wp_object_cache->redis ) {
			try {
				$wp_object_cache->redis->close();
			} catch ( \Exception $e ) {
				unset( $e );
			}
		}
		if ( $wp_object_cache->redis_replica ) {
			try {
				$wp_object_cache->redis_replica->close();
			} catch ( \Exception $e ) {
				unset( $e );
			}
		}
	}
	return true;
}

/**
 * Gets multiple values from the cache.
 *
 * @param array  $keys  Array of keys.
 * @param string $group Cache group.
 * @param bool   $force Force from Redis.
 * @return array Array of return values.
 */
function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
	global $wp_object_cache;
	return $wp_object_cache->get_multiple( $keys, $group, $force );
}

/**
 * Sets multiple values to the cache.
 *
 * @param array  $data   Array of keys and values.
 * @param string $group  Cache group.
 * @param int    $expire Expiration.
 * @return bool True on success.
 */
function wp_cache_set_multiple( $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set_multiple( $data, $group, (int) $expire );
}

/**
 * Deletes multiple values from the cache.
 *
 * @param array  $keys  Array of keys.
 * @param string $group Cache group.
 * @return bool True on success.
 */
function wp_cache_delete_multiple( $keys, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->delete_multiple( $keys, $group );
}

if ( ! function_exists( 'wp_cache_get_salted' ) ) {
	/**
	 * Retrieves salted data from the cache (WP 6.9+ native override).
	 *
	 * Uses the same wrapper-at-stable-key format as WP core's
	 * cache-compat.php: the salt is stored alongside the data in a wrapper
	 * array at the original cache key. Each write overwrites the same key,
	 * so Redis memory stays bounded and a stale salt yields a miss rather
	 * than leaving orphaned keys behind.
	 *
	 * @since NEXT
	 *
	 * @param string          $cache_key The cache key used for storage and retrieval.
	 * @param string          $group     The cache group used for organizing data.
	 * @param string|string[] $salt      The salt indicating when the cache group was last updated.
	 * @return mixed|false The cached data, or false if not found or outdated.
	 */
	function wp_cache_get_salted( $cache_key, $group, $salt ) {
		global $wp_object_cache;
		$salt  = is_array( $salt ) ? implode( ':', $salt ) : $salt;
		$cache = $wp_object_cache->get( $cache_key, $group );
		if ( ! is_array( $cache ) || ! isset( $cache['salt'], $cache['data'] ) || $salt !== $cache['salt'] ) {
			return false;
		}
		return $cache['data'];
	}
}

if ( ! function_exists( 'wp_cache_set_salted' ) ) {
	/**
	 * Stores salted data in the cache (WP 6.9+ native override).
	 *
	 * Stores the data wrapped in an array with its salt at the original cache
	 * key, exactly as WP core's cache-compat.php does. The stable key means a
	 * later write with a new salt overwrites the previous value and non-salted
	 * wp_cache_delete() calls can still invalidate the entry.
	 *
	 * @since NEXT
	 *
	 * @param string          $cache_key The cache key under which to store the data.
	 * @param mixed           $data      The data to be cached.
	 * @param string          $group     The cache group to which the data belongs.
	 * @param string|string[] $salt      The salt indicating when the cache group was last updated.
	 * @param int             $expire    When to expire the cache contents, in seconds.
	 * @return bool True on success, false on failure.
	 */
	function wp_cache_set_salted( $cache_key, $data, $group, $salt, $expire = 0 ) {
		global $wp_object_cache;
		$salt = is_array( $salt ) ? implode( ':', $salt ) : $salt;
		return $wp_object_cache->set(
			$cache_key,
			array(
				'data' => $data,
				'salt' => $salt,
			),
			$group,
			(int) $expire
		);
	}
}

if ( ! function_exists( 'wp_cache_get_multiple_salted' ) ) {
	/**
	 * Retrieves multiple salted items from the cache (WP 6.9+ native override).
	 *
	 * @since NEXT
	 *
	 * @param string[]        $cache_keys Array of cache keys to retrieve.
	 * @param string          $group      The group of the cache to check.
	 * @param string|string[] $salt       The salt indicating when the cache group was last updated.
	 * @return array Associative array of cache values, keyed by cache key; false when missing or outdated.
	 */
	function wp_cache_get_multiple_salted( $cache_keys, $group, $salt ) {
		global $wp_object_cache;
		$salt  = is_array( $salt ) ? implode( ':', $salt ) : $salt;
		$cache = $wp_object_cache->get_multiple( $cache_keys, $group );

		foreach ( $cache as $key => $value ) {
			if ( ! is_array( $value ) ) {
				$cache[ $key ] = false;
				continue;
			}
			if ( ! isset( $value['salt'], $value['data'] ) || $salt !== $value['salt'] ) {
				$cache[ $key ] = false;
				continue;
			}
			$cache[ $key ] = $value['data'];
		}

		return $cache;
	}
}

if ( ! function_exists( 'wp_cache_set_multiple_salted' ) ) {
	/**
	 * Stores multiple salted items in the cache (WP 6.9+ native override).
	 *
	 * @since NEXT
	 *
	 * @param mixed[]         $data   Associative array of keys and values to store.
	 * @param string          $group  The group to which the cached data belongs.
	 * @param string|string[] $salt   The salt indicating when the cache group was last updated.
	 * @param int             $expire When to expire the cache contents, in seconds.
	 * @return bool[] Array of return values keyed by cache key.
	 */
	function wp_cache_set_multiple_salted( $data, $group, $salt, $expire = 0 ) {
		global $wp_object_cache;
		$salt      = is_array( $salt ) ? implode( ':', $salt ) : $salt;
		$new_cache = array();
		foreach ( $data as $key => $value ) {
			$new_cache[ $key ] = array(
				'data' => $value,
				'salt' => $salt,
			);
		}
		return $wp_object_cache->set_multiple( $new_cache, $group, (int) $expire );
	}
}

if ( ! function_exists( 'wp_cache_delete_salted' ) ) {
	/**
	 * Deletes salted data from the cache (WP 6.9+ native override).
	 *
	 * Mirrors core's cache-compat.php: fetch the wrapper, check salt, delete stable key only on match.
	 *
	 * @since NEXT
	 * @param string          $cache_key Cache key.
	 * @param string          $group     Cache group.
	 * @param string|string[] $salt      Salt when the group was last updated.
	 * @return bool True if deleted, false otherwise.
	 */
	function wp_cache_delete_salted( $cache_key, $group, $salt ) {
		global $wp_object_cache;
		$salt  = is_array( $salt ) ? implode( ':', $salt ) : $salt;
		$cache = $wp_object_cache->get( $cache_key, $group );
		if ( ! is_array( $cache ) || ! isset( $cache['salt'], $cache['data'] ) || $salt !== $cache['salt'] ) {
			return false;
		}
		return $wp_object_cache->delete( $cache_key, $group );
	}
}

if ( ! function_exists( 'wp_cache_delete_multiple_salted' ) ) {
	/**
	 * Deletes multiple salted items from the cache (WP 6.9+ native override).
	 *
	 * @since NEXT
	 * @param string[]        $cache_keys Array of cache keys.
	 * @param string          $group      Cache group.
	 * @param string|string[] $salt       Salt when the group was last updated.
	 * @return bool[] Array of results keyed by cache key.
	 */
	function wp_cache_delete_multiple_salted( $cache_keys, $group, $salt ) {
		global $wp_object_cache;
		$salt    = is_array( $salt ) ? implode( ':', $salt ) : $salt;
		$cache   = $wp_object_cache->get_multiple( $cache_keys, $group );
		$results = array();
		foreach ( $cache_keys as $key ) {
			$value = $cache[ $key ] ?? false;
			if ( ! is_array( $value ) || ! isset( $value['salt'], $value['data'] ) || $salt !== $value['salt'] ) {
				$results[ $key ] = false;
				continue;
			}
			$results[ $key ] = $wp_object_cache->delete( $key, $group );
		}
		return $results;
	}
}

if ( ! function_exists( 'wp_cache_supports' ) ) {
	/**
	 * Determines whether the object cache implementation supports a particular feature.
	 *
	 * @since NEXT
	 *
	 * @param string $feature The feature to check support for.
	 * @return bool True if the feature is supported, false otherwise.
	 */
	function wp_cache_supports( $feature ) {
		/*
		 * Keep this list in sync with core's wp_cache_supports() in wp-includes/cache.php.
		 * Re-diff the claim list against core each release and claim a new feature only
		 * when the drop-in actually implements it. The compatible feature list here is
		 * core's authoritative list (add_multiple, set_multiple, get_multiple,
		 * delete_multiple, flush_runtime, flush_group) minus flush_runtime:
		 *
		 *  - add_multiple is served by core's cache-compat.php fallback, which loops
		 *    wp_cache_add() per key, so it is genuinely supported.
		 *  - flush_runtime is intentionally NOT claimed: this drop-in has no runtime-only
		 *    flush, so core's compat wp_cache_flush_runtime() would delegate to a full
		 *    persistent Redis flush, which is not what a runtime-only caller expects.
		 */
		switch ( $feature ) {
			case 'add_multiple':
			case 'set_multiple':
			case 'get_multiple':
			case 'delete_multiple':
			case 'flush_group':
				return true;
			default:
				return false;
		}
	}
}

if ( ! function_exists( 'wp_cache_add_salt' ) ) {
	/**
	 * Adds salt to the cache key prefix (WP 6.9+).
	 *
	 * Allows core to invalidate all cached data by changing the key space.
	 * The drop-in must support this via WP_Object_Cache::add_salt().
	 *
	 * @since NEXT
	 *
	 * @param string $salt The salt string to add.
	 * @return void
	 */
	function wp_cache_add_salt( $salt ) {
		global $wp_object_cache;
		if ( $wp_object_cache instanceof WP_Object_Cache ) {
			$wp_object_cache->add_salt( $salt );
		}
	}

}
