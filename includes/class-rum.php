<?php
/**
 * Real-user Web Vitals (RUM) collection and reporting.
 *
 * @package PerformanceOptimise
 */

namespace PerformanceOptimise\Inc;

if ( ! class_exists( 'PerformanceOptimise\Inc\RUM' ) ) {

	/**
	 * Collects real-visitor Core Web Vitals beacons and stores them as bounded
	 * per-day/per-path aggregates, plus the frontend beacon + admin data API.
	 *
	 * The beacon endpoint is intentionally public (anonymous visitors) so it is
	 * protected with a daily rolling token and per-IP rate limiting instead of
	 * the manage_options permission used by the admin endpoints.
	 *
	 * @since 2.18.0
	 */
	class RUM {

		/**
		 * Option storing the aggregated trend data.
		 *
		 * @var string
		 */
		const OPTION = 'wppo_web_vitals_rum';

		/**
		 * How many days of history to retain.
		 *
		 * @var int
		 */
		const MAX_DAYS = 14;

		/**
		 * Maximum paths retained per day.
		 *
		 * @var int
		 */
		const MAX_PATHS_PER_DAY = 200;

		/**
		 * Maximum beacons accepted per IP per hour.
		 *
		 * @var int
		 */
		const RATE_LIMIT_PER_HOUR = 120;

		/**
		 * Whether RUM collection is enabled.
		 *
		 * @return bool
		 */
		public static function is_enabled(): bool {
			$options = get_option( 'wppo_settings', array() );
			return ! empty( $options['performance_audit']['rum_enabled'] );
		}

		/**
		 * Handle a RUM beacon.
		 *
		 * @param array $params Decoded JSON body from the beacon request.
		 * @return array{ok:bool,status:int,message:string}
		 */
		public static function collect( array $params ): array {
			if ( ! self::is_enabled() ) {
				return array(
					'ok'      => false,
					'status'  => 400,
					'message' => __( 'RUM is disabled.', 'performance-optimisation' ),
				);
			}

			$token = isset( $params['token'] ) ? sanitize_text_field( (string) $params['token'] ) : '';
			if ( ! self::is_valid_token( $token ) ) {
				return array(
					'ok'      => false,
					'status'  => 401,
					'message' => __( 'Invalid beacon token.', 'performance-optimisation' ),
				);
			}

			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			if ( '' !== $ip && self::is_rate_limited( $ip ) ) {
				return array(
					'ok'      => false,
					'status'  => 429,
					'message' => __( 'Too many beacons.', 'performance-optimisation' ),
				);
			}

			$sample = self::sanitize_sample( $params );
			if ( null === $sample ) {
				return array(
					'ok'      => false,
					'status'  => 400,
					'message' => __( 'Invalid beacon payload.', 'performance-optimisation' ),
				);
			}

			self::store_sample( $sample );

			return array(
				'ok'      => true,
				'status'  => 200,
				'message' => '',
			);
		}

		/**
		 * Retrieve the aggregated RUM data for the admin dashboard.
		 *
		 * @return array
		 */
		public static function get_data(): array {
			$data = get_option( self::OPTION, array() );
			return is_array( $data ) ? $data : array();
		}

		/**
		 * Enqueue the frontend beacon script on the public site.
		 *
		 * @return void
		 */
		public static function maybe_enqueue_scripts(): void {
			if ( ! self::is_enabled() || is_admin() ) {
				return;
			}

			$asset_file = WPPO_PLUGIN_PATH . 'build/rum.asset.php';
			$deps       = array();
			$version    = WPPO_VERSION;
			if ( file_exists( $asset_file ) ) {
				$asset   = include $asset_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
				$deps    = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
				$version = isset( $asset['version'] ) ? $asset['version'] : WPPO_VERSION;
			}

			wp_enqueue_script( 'wppo-rum', WPPO_PLUGIN_URL . 'build/rum.js', $deps, $version, true );
		}

		/**
		 * Print the beacon config inline so it is baked into cached HTML.
		 *
		 * Runs on wp_footer; cached pages generated through WordPress capture it
		 * and the served (cache-hit) HTML therefore keeps working without WP.
		 *
		 * @return void
		 */
		public static function print_config(): void {
			if ( ! self::is_enabled() ) {
				return;
			}

			$config = array(
				'apiUrl' => esc_url_raw( rest_url( 'performance-optimisation/v1/rum_collect' ) ),
				'token'  => self::token_for( time() ),
				'path'   => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/',
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded config.
			echo '<script id="wppo-rum-config">window.wppoRum=' . wp_json_encode( $config ) . ';</script>' . "\n";
		}

		/**
		 * Rolling daily token for the beacon.
		 *
		 * @param int $timestamp Unix timestamp.
		 * @return string
		 */
		private static function token_for( int $timestamp ): string {
			return wp_hash( 'wppo_rum_' . gmdate( 'Ymd', $timestamp ) );
		}

		/**
		 * Validate the beacon token against today or yesterday.
		 *
		 * @param string $token Beacon token.
		 * @return bool
		 */
		private static function is_valid_token( string $token ): bool {
			if ( '' === $token ) {
				return false;
			}
			$now = time();
			foreach ( array( $now, $now - DAY_IN_SECONDS ) as $timestamp ) {
				if ( hash_equals( self::token_for( $timestamp ), $token ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Whether the given IP has exceeded the hourly beacon budget.
		 *
		 * @param string $ip Client IP address.
		 * @return bool
		 */
		private static function is_rate_limited( string $ip ): bool {
			$key   = Util::transient_key( 'wppo_rum_ratelimit_' . md5( $ip ) );
			$count = (int) get_transient( $key );
			if ( $count >= self::RATE_LIMIT_PER_HOUR ) {
				return true;
			}
			set_transient( $key, $count + 1, HOUR_IN_SECONDS );
			return false;
		}

		/**
		 * Validate and clamp a beacon sample.
		 *
		 * @param array $params Raw beacon payload.
		 * @return array|null Normalized sample or null when invalid.
		 */
		private static function sanitize_sample( array $params ): ?array {
			$raw_path = isset( $params['path'] ) ? sanitize_text_field( (string) $params['path'] ) : '';
			if ( '' === $raw_path ) {
				return null;
			}

			$parsed_path = wp_parse_url( $raw_path, PHP_URL_PATH );
			$path        = is_string( $parsed_path ) && '' !== $parsed_path ? $parsed_path : '/';
			$path        = substr( $path, 0, 512 );

			$ranges = array(
				'ttfb' => array( 0, 60000 ),
				'fcp'  => array( 0, 60000 ),
				'lcp'  => array( 0, 60000 ),
				'inp'  => array( 0, 60000 ),
				'cls'  => array( 0, 1 ),
			);

			$sample  = array( 'path' => $path );
			$has_any = false;
			foreach ( $ranges as $metric => $range ) {
				if ( ! isset( $params[ $metric ] ) ) {
					continue;
				}
				$value = (float) $params[ $metric ];
				if ( ! is_finite( $value ) ) {
					return null;
				}
				$sample[ $metric ] = max( $range[0], min( $range[1], $value ) );
				$has_any           = true;
			}

			if ( ! $has_any ) {
				return null;
			}

			return $sample;
		}

		/**
		 * Merge a sample into the per-day/per-path aggregate option.
		 *
		 * @param array $sample Normalized sample.
		 * @return void
		 */
		private static function store_sample( array $sample ): void {
			$date = gmdate( 'Y-m-d' );
			$path = $sample['path'];

			$all = get_option( self::OPTION, array() );
			if ( ! is_array( $all ) ) {
				$all = array();
			}
			if ( ! isset( $all[ $date ] ) ) {
				$all[ $date ] = array();
			}

			$day    = $all[ $date ];
			$bucket = isset( $day[ $path ] ) ? $day[ $path ] : array();

			foreach ( array( 'ttfb', 'fcp', 'lcp', 'inp', 'cls' ) as $metric ) {
				if ( ! isset( $sample[ $metric ] ) ) {
					continue;
				}
				$value = (float) $sample[ $metric ];
				if ( ! isset( $bucket[ $metric ] ) ) {
					$bucket[ $metric ] = array(
						'n'   => 0,
						'sum' => 0.0,
						'min' => $value,
						'max' => $value,
					);
				}
				++$bucket[ $metric ]['n'];
				$bucket[ $metric ]['sum'] += $value;
				$bucket[ $metric ]['min']  = min( $bucket[ $metric ]['min'], $value );
				$bucket[ $metric ]['max']  = max( $bucket[ $metric ]['max'], $value );
			}

			$day[ $path ] = $bucket;

			// Bound paths per day (drop the oldest-inserted path when over budget).
			$path_total = count( $day );
			while ( $path_total > self::MAX_PATHS_PER_DAY ) {
				array_shift( $day );
				--$path_total;
			}

			$all[ $date ] = $day;

			// Drop days older than the retention window.
			$cutoff = gmdate( 'Y-m-d', time() - ( self::MAX_DAYS * DAY_IN_SECONDS ) );
			foreach ( array_keys( $all ) as $day_key ) {
				if ( $day_key < $cutoff ) {
					unset( $all[ $day_key ] );
				}
			}

			update_option( self::OPTION, $all, false );
		}
	}
}
