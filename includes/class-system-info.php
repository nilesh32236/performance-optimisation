<?php
/**
 * System Info Class
 *
 * Collects PHP, database, WordPress, server, and cache environment details
 * for display in the WPPO admin dashboard. All fields are null-safe — missing
 * server variables return null rather than triggering PHP warnings.
 *
 * @package PerformanceOptimise\Inc
 * @since   1.5.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\System_Info' ) ) {

	/**
	 * Class System_Info
	 *
	 * Provides static methods to gather server, PHP, database, WordPress,
	 * and cache environment information.
	 *
	 * @since 1.5.0
	 */
	class System_Info {

		/**
		 * Known cache plugin slugs used to detect active cache plugins.
		 * Does NOT include 'performance-optimisation' — this plugin is never
		 * reported as the active cache plugin for third-party detection purposes.
		 *
		 * @var   string[]
		 * @since 1.5.0
		 */
		private static array $cache_plugin_slugs = array(
			'w3-total-cache',
			'wp-super-cache',
			'wp-rocket',
			'litespeed-cache',
			'redis-cache',
			'wp-fastest-cache',
			'comet-cache',
			'hyper-cache',
		);

		/**
		 * Return all system info groups in a single array.
		 *
		 * @since  1.5.0
		 * @return array {
		 *     @type array $php          PHP environment details.
		 *     @type array $database     Database details.
		 *     @type array $wordpress    WordPress installation details.
		 *     @type array $wp_constants Key WordPress constants.
		 *     @type array $server       Server environment details.
		 *     @type array $cache        Cache status details.
		 *     @type array $infrastructure Infrastructure details.
		 *     @type array $opcache      Opcode cache details.
		 * }
		 */
		public static function get_all(): array {
			return array(
				'php'            => self::get_php(),
				'database'       => self::get_database(),
				'wordpress'      => self::get_wordpress(),
				'wp_constants'   => self::get_wp_constants(),
				'server'         => self::get_server(),
				'cache'          => self::get_cache(),
				'litespeed'      => self::get_litespeed(),
				'infrastructure' => self::get_infrastructure(),
				'opcache'        => self::get_opcache(),
			);
		}

		/**
		 * Get PHP environment details.
		 *
		 * @since  1.5.0
		 * @return array {
		 *     @type string|null $version             PHP major.minor version series (patch level redacted).
		 *     @type string|null $sapi                PHP SAPI name.
		 *     @type string|null $memory_limit         memory_limit ini value.
		 *     @type string|null $max_execution_time   max_execution_time ini value.
		 *     @type string|null $upload_max_filesize  upload_max_filesize ini value.
		 *     @type string|null $post_max_size        post_max_size ini value.
		 *     @type string|null $display_errors       display_errors ini value.
		 *     @type int         $extensions_count     Number of loaded PHP extensions.
		 * }
		 */
		public static function get_php(): array {
			$memory_limit        = ini_get( 'memory_limit' );
			$max_execution_time  = ini_get( 'max_execution_time' );
			$upload_max_filesize = ini_get( 'upload_max_filesize' );
			$post_max_size       = ini_get( 'post_max_size' );
			$display_errors      = ini_get( 'display_errors' );

			return array(
				// Security: expose only the major.minor series so the exact,
				// fingerprintable patch version never leaves the server.
				'version'             => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
				'sapi'                => php_sapi_name(),
				'memory_limit'        => false !== $memory_limit ? $memory_limit : null,
				'max_execution_time'  => false !== $max_execution_time ? $max_execution_time : null,
				'upload_max_filesize' => false !== $upload_max_filesize ? $upload_max_filesize : null,
				'post_max_size'       => false !== $post_max_size ? $post_max_size : null,
				'display_errors'      => false !== $display_errors ? $display_errors : null,
				'extensions_count'    => count( get_loaded_extensions() ),
			);
		}

		/**
		 * Get database environment details.
		 *
		 * @since  1.5.0
		 * @global \wpdb $wpdb WordPress database abstraction object.
		 * @return array {
		 *     @type string|null $server_version  MySQL/MariaDB server version (major.minor only).
		 *     @type string|null $extension        PHP database extension class name.
		 *     @type string|null $client_version   Client library version.
		 *     @type string|null $max_connections  max_connections MySQL variable.
		 * }
		 */
		public static function get_database(): array {
			global $wpdb;

			$extension      = null;
			$client_version = null;

			if ( isset( $wpdb->dbh ) && is_object( $wpdb->dbh ) ) {
				$extension = get_class( $wpdb->dbh );
			}

			return array(
				// Security: major.minor only — patch/build suffixes (e.g.
				// "-MariaDB", "-log") are dropped to hinder fingerprinting.
				'server_version'  => self::redact_version( $wpdb->db_version() ),
				'extension'       => $extension,
				'client_version'  => $client_version,
				'max_connections' => self::get_mysql_var( 'max_connections' ),
			);
		}

		/**
		 * Get WordPress installation details.
		 *
		 * @since  1.5.0
		 * @return array {
		 *     @type string $version              WordPress version.
		 *     @type string $environment_type     WP_ENVIRONMENT_TYPE constant value.
		 *     @type string $permalink_structure  Current permalink structure.
		 *     @type string $using_https          'Yes' or 'No'.
		 *     @type string $multisite            'Yes' or 'No'.
		 * }
		 */
		public static function get_wordpress(): array {
			$multisite = false;
			if ( function_exists( 'is_multisite' ) ) {
				try {
					$multisite = is_multisite();
				} catch ( \Throwable $e ) {
					$multisite = false;
				}
			}
			return array(
				'version'             => get_bloginfo( 'version' ),
				'environment_type'    => wp_get_environment_type(),
				'permalink_structure' => get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : __( 'Default', 'performance-optimisation' ),
				'using_https'         => is_ssl() ? __( 'Yes', 'performance-optimisation' ) : __( 'No', 'performance-optimisation' ),
				'multisite'           => $multisite ? __( 'Yes', 'performance-optimisation' ) : __( 'No', 'performance-optimisation' ),
			);
		}

		/**
		 * Get key WordPress constants.
		 *
		 * @since  1.5.0
		 * @return array {
		 *     @type string $WP_DEBUG        'true', 'false', or 'undefined'.
		 *     @type string $WP_CACHE        'true', 'false', or 'undefined'.
		 *     @type string $WP_MEMORY_LIMIT Configured memory limit or 'undefined'.
		 *     @type string $WP_DEBUG_LOG    'true', 'false', or 'undefined'.
		 *     @type string $SCRIPT_DEBUG    'true', 'false', or 'undefined'.
		 * }
		 */
		public static function get_wp_constants(): array {
			return array(
				'WP_DEBUG'        => self::format_constant( 'WP_DEBUG' ),
				'WP_CACHE'        => self::format_constant( 'WP_CACHE' ),
				'WP_MEMORY_LIMIT' => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'undefined',
				'WP_DEBUG_LOG'    => self::format_constant( 'WP_DEBUG_LOG' ),
				'SCRIPT_DEBUG'    => self::format_constant( 'SCRIPT_DEBUG' ),
			);
		}

		/**
		 * Get server environment details.
		 *
		 * All values are null-safe — missing $_SERVER keys return null.
		 *
		 * @since  1.5.0
		 * @return array {
		 *     @type string|null $server_software  Normalized server family name (version banner redacted).
		 *     @type string      $os               OS name and kernel version.
		 *     @type string      $architecture     CPU architecture.
		 * }
		 */
		public static function get_server(): array {
			return array(
				// Security: report the normalized server family instead of the
				// raw SERVER_SOFTWARE banner, which embeds exact versions.
				'server_software' => self::normalize_server_software(
					isset( $_SERVER['SERVER_SOFTWARE'] )
						? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
						: null
				),
				'os'              => PHP_OS . ' ' . php_uname( 'r' ),
				'architecture'    => php_uname( 'm' ),
			);
		}

		/**
		 * Get cache environment details.
		 *
		 * @since  1.5.0
		 * @return array {
		 *     @type string          $object_cache_status  'Enabled' or 'Disabled'.
		 *     @type string          $active_cache_plugin  Slug of active cache plugin or 'None'.
		 *     @type string          $peak_memory_usage    Human-readable peak memory usage.
		 *     @type string          $current_memory_usage Human-readable current memory usage.
		 *     @type string[]|null   $woocommerce_presets  WooCommerce high-value URL presets, or null.
		 * }
		 */
		public static function get_cache(): array {
			return array(
				'object_cache_status'  => wp_using_ext_object_cache()
					? esc_html__( 'Enabled', 'performance-optimisation' )
					: esc_html__( 'Disabled', 'performance-optimisation' ),
				'active_cache_plugin'  => self::get_active_cache_plugin(),
				'peak_memory_usage'    => size_format( memory_get_peak_usage( true ) ),
				'current_memory_usage' => size_format( memory_get_usage() ),
			);
		}

		/**
		 * Get LiteSpeed environment details.
		 *
		 * Exposes detection + coexistence mode + drop-in arbitration for the SPA.
		 * Null-safe and cached per request via LiteSpeed_Integration statics.
		 *
		 * @since  NEXT
		 * @return array{
		 *     detected: bool,
		 *     server_type: string,
		 *     lscache_active: bool,
		 *     mode: string,
		 *     effective_mode: string,
		 *     wppo_owns_cache: bool,
		 *     optimizer_disabled: bool,
		 *     dropin: array{
		 *         advanced_cache: string,
		 *         object_cache: string
		 *     }
		 * }
		 */
		public static function get_litespeed(): array {
			$info = array(
				'detected'           => false,
				'server_type'        => 'other',
				'lscache_active'     => false,
				'mode'               => 'auto',
				'effective_mode'     => 'standalone',
				'wppo_owns_cache'    => true,
				'optimizer_disabled' => false,
				'vary_groups'        => array(
					'guest'     => false,
					'mobile'    => false,
					'webp'      => false,
					'commenter' => false,
					'postpass'  => false,
				),
				'crawler'            => array(
					'concurrency'        => 2,
					'blacklistThreshold' => 3,
				),
				'esi_available'      => false,
				'dropin'             => array(
					'advanced_cache' => 'none',
					'object_cache'   => 'none',
				),
			);

			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'get_info' ) ) {
				$ls_info = LiteSpeed_Integration::get_info();
				$info    = array_merge( $info, $ls_info );
				// P2 vary groups.
				if ( method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'get_vary_groups' ) ) {
					$info['vary_groups'] = LiteSpeed_Integration::get_vary_groups();
				}
				// P4 crawler.
				if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Crawler' ) ) {
					$info['crawler'] = array(
						'concurrency'        => LiteSpeed_Crawler::get_concurrency(),
						'blacklistThreshold' => LiteSpeed_Crawler::get_blacklist_threshold(),
					);
				}
				// P5 ESI.
				if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_ESI' ) ) {
					$info['esi_available'] = LiteSpeed_ESI::is_esi_available();
				}
				// Map effective_mode to wppo_owns logic already in get_info.
				if ( isset( $ls_info['detected'] ) ) {
					$info['detected'] = (bool) $ls_info['detected'];
				}
			} elseif ( class_exists( 'PerformanceOptimise\Inc\Server_Rules' ) && method_exists( 'PerformanceOptimise\Inc\Server_Rules', 'get_server_type' ) ) {
				$type                = Server_Rules::get_server_type();
				$info['server_type'] = $type;
				$info['detected']    = 'litespeed' === $type;
			}

			// Drop-in arbitration: advanced-cache.php.
			$adv = 'none';
			if ( class_exists( 'PerformanceOptimise\Inc\Advanced_Cache_Handler' ) ) {
				try {
					if ( Advanced_Cache_Handler::is_our_dropin() ) {
						$adv = 'wppo';
					} elseif ( Advanced_Cache_Handler::foreign_dropin_present() ) {
						// Try to distinguish LSCache foreign drop-in vs other.
						$path     = Advanced_Cache_Handler::get_dropin_path();
						$contents = '';
						if ( is_readable( $path ) && filesize( $path ) < 1048576 ) {
							$contents_raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
							if ( is_string( $contents_raw ) ) {
								$contents = $contents_raw;
							}
						}
						if ( false !== strpos( $contents, 'litespeed' ) || false !== strpos( $contents, 'LSCACHE' ) || false !== strpos( $contents, 'LSCWP' ) ) {
							$adv = 'litespeed';
						} else {
							$adv = 'foreign';
						}
					}
				} catch ( \Throwable $e ) {
					$adv = 'none';
				}
			}
			$info['dropin']['advanced_cache'] = $adv;

			// Drop-in arbitration: object-cache.php.
			$obj = 'none';
			if ( class_exists( 'PerformanceOptimise\Inc\Object_Cache' ) ) {
				try {
					$oc     = new Object_Cache();
					$status = $oc->get_status();
					if ( ! empty( $status['enabled'] ) ) {
						$obj = 'wppo';
					} elseif ( ! empty( $status['foreign_dropin'] ) ) {
						// Try to detect if foreign is LSCache's object cache.
						$path     = WP_CONTENT_DIR . '/object-cache.php';
						$contents = '';
						if ( is_readable( $path ) && filesize( $path ) < 1048576 ) {
							$contents_raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
							if ( is_string( $contents_raw ) ) {
								$contents = $contents_raw;
							}
						}
						if ( false !== strpos( $contents, 'litespeed' ) || false !== strpos( $contents, 'LSCache' ) ) {
							$obj = 'litespeed';
						} else {
							$obj = 'foreign';
						}
					}
				} catch ( \Throwable $e ) {
					$obj = 'none';
				}
			}
			$info['dropin']['object_cache'] = $obj;

			return $info;
		}

		/**
		 * Get infrastructure environment details.
		 *
		 * @since  1.6.0
		 * @return array {
		 *     @type array $action_scheduler Action Scheduler status.
		 *     @type array $pagespeed_api    PageSpeed API status.
		 * }
		 */
		public static function get_infrastructure(): array {
			$options = get_option( 'wppo_settings', array() );
			return array(
				'action_scheduler' => array(
					'available' => function_exists( 'as_enqueue_async_action' ),
					'label'     => __( 'Action Scheduler', 'performance-optimisation' ),
				),
				'pagespeed_api'    => array(
					'configured' => ! empty( $options['performance_audit']['pagespeed_api_key'] ),
					'label'      => __( 'PageSpeed Insights API', 'performance-optimisation' ),
				),
			);
		}

		/**
		 * Get PHP opcode cache environment details.
		 *
		 * Mirrors WordPress core's Site Health server info (added in WP 7.0),
		 * adapted to the class's null-safe, scalar-only conventions. Field
		 * keys vary by PHP version/host, so each one is guarded with isset().
		 *
		 * @since  1.8.0
		 * @return array {
		 *     @type string $status           'Enabled' or 'Disabled'.
		 *     @type string $detail           'not available' when the cache is unusable.
		 *     @type string $memory_usage     Used of total memory, e.g. '100 MB of 256 MB'.
		 *     @type string $interned_strings Interned strings usage as a percentage.
		 *     @type string $hit_rate         Cache hit rate percentage.
		 *     @type string $cache_full       'Yes' or 'No'.
		 * }
		 */
		public static function get_opcache(): array {
			if ( ! function_exists( 'opcache_get_status' ) ) {
				return array(
					'status' => esc_html__( 'Disabled', 'performance-optimisation' ),
					'detail' => 'not available',
				);
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Warning emitted when opcache.restrict_api blocks access.
			$opcache = opcache_get_status( false );

			if ( false === $opcache ) {
				return array(
					'status' => esc_html__( 'Disabled by configuration', 'performance-optimisation' ),
					'detail' => 'not available',
				);
			}

			$info = array(
				'status'     => ! empty( $opcache['opcache_enabled'] )
					? esc_html__( 'Enabled', 'performance-optimisation' )
					: esc_html__( 'Disabled', 'performance-optimisation' ),
				'cache_full' => ! empty( $opcache['cache_full'] )
					? esc_html__( 'Yes', 'performance-optimisation' )
					: esc_html__( 'No', 'performance-optimisation' ),
			);

			if ( isset( $opcache['memory_usage']['used_memory'], $opcache['memory_usage']['free_memory'] ) ) {
				$info['memory_usage'] = sprintf(
					/* translators: 1: Used memory, 2: Total memory */
					esc_html__( '%1$s of %2$s', 'performance-optimisation' ),
					size_format( $opcache['memory_usage']['used_memory'] ),
					size_format( $opcache['memory_usage']['free_memory'] + $opcache['memory_usage']['used_memory'] )
				);
			}

			if (
				isset( $opcache['interned_strings_usage']['used_memory'], $opcache['interned_strings_usage']['buffer_size'] )
				&& 0 !== $opcache['interned_strings_usage']['buffer_size']
			) {
				$info['interned_strings'] = sprintf(
					/* translators: 1: Percentage used, 2: Total memory, 3: Free memory */
					esc_html__( '%1$s%% of %2$s (%3$s free)', 'performance-optimisation' ),
					number_format_i18n( ( $opcache['interned_strings_usage']['used_memory'] / $opcache['interned_strings_usage']['buffer_size'] ) * 100, 2 ),
					size_format( $opcache['interned_strings_usage']['buffer_size'] ),
					size_format( isset( $opcache['interned_strings_usage']['free_memory'] ) ? $opcache['interned_strings_usage']['free_memory'] : 0 )
				);
			}

			if ( isset( $opcache['opcache_statistics']['opcache_hit_rate'] ) ) {
				$info['hit_rate'] = sprintf(
					/* translators: %s: Hit rate percentage */
					esc_html__( '%s%%', 'performance-optimisation' ),
					number_format_i18n( $opcache['opcache_statistics']['opcache_hit_rate'], 2 )
				);
			}

			return $info;
		}

		/**
		 * Get the request start time from REQUEST_TIME_FLOAT, or null if unavailable.
		 *
		 * @since  1.9.0
		 * @return float|null Request start timestamp in Unix seconds, or null.
		 */
		public static function get_request_start_microtime(): ?float {
			if ( ! isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
				return null;
			}
			$value = (float) $_SERVER['REQUEST_TIME_FLOAT']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			return $value > 0.0 ? $value : null;
		}

		/**
		 * Get WooCommerce high-value URL presets.
		 *
		 * Returns checkout and cart URLs when WooCommerce is active.
		 *
		 * @since  1.5.0
		 * @return string[]|null Array of preset URLs, or null if WooCommerce is not active.
		 */
		public static function get_woocommerce_presets(): ?array {
			if ( ! function_exists( 'wc_get_checkout_url' ) ) {
				return null;
			}

			$presets = array();

			$checkout_url = wc_get_checkout_url();
			if ( $checkout_url ) {
				$presets[] = esc_url_raw( $checkout_url );
			}

			$cart_url = wc_get_cart_url();
			if ( $cart_url ) {
				$presets[] = esc_url_raw( $cart_url );
			}

			return ! empty( $presets ) ? $presets : null;
		}

		/**
		 * Get the slug of the first detected active cache plugin.
		 *
		 * @since  1.5.0
		 * @return string Plugin slug or 'None' if no cache plugin is active.
		 */
		private static function get_active_cache_plugin(): string {
			$active_plugins = (array) get_option( 'active_plugins', array() );

			// On multisite, also check network-activated plugins.
			if ( function_exists( 'is_multisite' ) ) {
				$is_multisite = false;
				try {
					$is_multisite = is_multisite();
				} catch ( \Throwable $e ) {
					$is_multisite = false;
				}
				if ( $is_multisite ) {
					try {
						$network_plugins = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
						$active_plugins  = array_merge( $active_plugins, $network_plugins );
					} catch ( \Throwable $e ) {
						unset( $e ); // Missing mock — treat as no network plugins.
					}
				}
			}

			foreach ( $active_plugins as $plugin_path ) {
				$slug = dirname( $plugin_path );
				// Single-file plugins have dirname of '.'.
				if ( '.' === $slug ) {
					$slug = str_replace( '.php', '', basename( $plugin_path ) );
				}
				if ( in_array( $slug, self::$cache_plugin_slugs, true ) ) {
					return $slug;
				}
			}

			return esc_html__( 'None', 'performance-optimisation' );
		}

		/**
		 * Retrieve a MySQL/MariaDB server variable.
		 *
		 * @since  1.5.0
		 * @param  string $variable The MySQL variable name to retrieve.
		 * @return string|null The variable value, or null if not found.
		 */
		private static function get_mysql_var( string $variable ): ?string {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SHOW VARIABLES LIKE %s', $variable )
			);

			// SHOW VARIABLES returns two columns: Variable_name and Value.
			// get_var() returns column 0 (Variable_name) by default, so we use get_row().
			return isset( $row->Value ) ? (string) $row->Value : null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		/**
		 * Redact a software version string to its major.minor series.
		 *
		 * Drops the patch level and any build/platform suffix (e.g. "-MariaDB",
		 * "-log") so exact server versions cannot be fingerprinted from the
		 * system info endpoint. Unparseable values are reported as null.
		 *
		 * @since  NEXT
		 * @param  string|null $version Raw version string.
		 * @return string|null Major.minor version series, or null when unavailable or unparseable.
		 */
		private static function redact_version( ?string $version ): ?string {
			if ( null === $version || '' === $version ) {
				return null;
			}

			if ( preg_match( '/^(\d+\.\d+)/', $version, $matches ) ) {
				return $matches[1];
			}

			return null;
		}

		/**
		 * Normalize a raw SERVER_SOFTWARE banner into a server family name.
		 *
		 * The raw banner embeds exact version numbers (e.g. "Apache/2.4.41"),
		 * which is fingerprintable; only the product family is exposed.
		 *
		 * @since  NEXT
		 * @param  string|null $software Raw SERVER_SOFTWARE value.
		 * @return string|null Normalized family name, 'Unknown' for unrecognized
		 *                     servers, or null when unavailable.
		 */
		private static function normalize_server_software( ?string $software ): ?string {
			if ( null === $software || '' === $software ) {
				return null;
			}

			$families = array(
				'openlitespeed' => 'OpenLiteSpeed',
				'litespeed'     => 'LiteSpeed',
				'apache'        => 'Apache',
				'nginx'         => 'nginx',
				'caddy'         => 'Caddy',
				'cloudflare'    => 'Cloudflare',
				'iis'           => 'IIS',
			);

			foreach ( $families as $needle => $label ) {
				if ( false !== stripos( $software, $needle ) ) {
					return $label;
				}
			}

			return 'Unknown';
		}

		/**
		 * Format a boolean WordPress constant as a readable string.
		 *
		 * @since  1.5.0
		 * @param  string $constant The constant name to check.
		 * @return string 'true', 'false', or 'undefined'.
		 */
		private static function format_constant( string $constant ): string {
			if ( ! defined( $constant ) ) {
				return 'undefined';
			}
			return constant( $constant ) ? 'true' : 'false';
		}
	}
}
