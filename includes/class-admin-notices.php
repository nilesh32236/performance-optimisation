<?php
/**
 * Admin notices: activation issues, cache conflicts, onboarding.
 *
 * @package PerformanceOptimise\Inc
 * @since   1.2.1
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Admin_Notices' ) ) {

	/**
	 * Admin notices handler.
	 */
	class Admin_Notices {

		/**
		 * Other full-page caching plugins that may conflict with WPPO drop-ins.
		 *
		 * @var array<string, string> Plugin file => human label.
		 */
		private const COMPETING_CACHE_PLUGINS = array(
			'wp-super-cache/wp-cache.php'            => 'WP Super Cache',
			'w3-total-cache/w3-total-cache.php'      => 'W3 Total Cache',
			'wp-fastest-cache/wpFastestCache.php'    => 'WP Fastest Cache',
			'litespeed-cache/litespeed-cache.php'    => 'LiteSpeed Cache',
			'cache-enabler/cache-enabler.php'        => 'Cache Enabler',
			'sg-cachepress/sg-cachepress.php'        => 'SG Optimizer',
			'wp-rocket/wp-rocket.php'                => 'WP Rocket',
			'comet-cache/comet-cache.php'            => 'Comet Cache',
			'swift-performance-lite/performance.php' => 'Swift Performance Lite',
			'swift-performance/performance.php'      => 'Swift Performance',
		);

		/**
		 * Register hooks.
		 */
		public function __construct() {
			add_action( 'admin_notices', array( $this, 'render_notices' ) );
			add_action( 'admin_init', array( $this, 'handle_dismiss' ) );
		}

		/**
		 * Dismiss notices via query arg + nonce.
		 *
		 * @return void
		 */
		public function handle_dismiss(): void {
			if ( ! isset( $_GET['wppo_dismiss'], $_GET['_wpnonce'] ) ) {
				return;
			}

			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wppo_dismiss_notice' ) ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$key = sanitize_key( wp_unslash( $_GET['wppo_dismiss'] ) );

			if ( 'activation' === $key ) {
				delete_transient( Util::transient_key( 'wppo_activation_notices' ) );
			}

			if ( 'review_done' === $key ) {
				update_option( 'wppo_review_dismissed', 1 );
			}

			if ( 'review_snooze' === $key ) {
				update_option( 'wppo_review_snoozed_until', time() + ( 30 * DAY_IN_SECONDS ) );
			}

			if ( 'litespeed' === $key ) {
				update_user_meta( get_current_user_id(), 'wppo_litespeed_notice_dismissed', 1 );
			}

			if ( 'object_cache_circuit' === $key ) {
				// Dismiss only this trip: persist its tripped_at timestamp so
				// the next trip (newer timestamp) automatically re-arms the notice.
				$tripped_at = 0;
				if ( class_exists( 'PerformanceOptimise\Inc\Object_Cache' ) ) {
					try {
						$circuit    = ( new Object_Cache() )->get_circuit_state();
						$tripped_at = isset( $circuit['tripped_at'] ) ? (int) $circuit['tripped_at'] : 0;
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				update_option( Object_Cache::CIRCUIT_DISMISSED_OPTION, $tripped_at > 0 ? $tripped_at : time(), false );
				delete_transient( Util::transient_key( Object_Cache::CIRCUIT_NOTICE_TRANSIENT ) );
			}

			wp_safe_redirect( remove_query_arg( array( 'wppo_dismiss', '_wpnonce' ) ) );
			exit;
		}

		/**
		 * Output notices.
		 *
		 * @return void
		 */
		public function render_notices(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$this->maybe_activation_notices();
			$this->maybe_competing_plugins_notice();
			$this->maybe_litespeed_coexistence_notice();
			$this->maybe_object_cache_circuit_notice();
			$this->maybe_builder_purge_notice();
			$this->maybe_review_notice();
		}

		/**
		 * One-time notices from activation (wp-config, foreign drop-in).
		 *
		 * @return void
		 */
		private function maybe_activation_notices(): void {
			$notices = get_transient( Util::transient_key( 'wppo_activation_notices' ) );

			if ( ! is_array( $notices ) || empty( $notices ) ) {
				return;
			}

			$messages = array();

			foreach ( $notices as $key ) {
				switch ( $key ) {
					case 'foreign_dropin':
						$messages[] = __( 'Another plugin or your host already manages <code>wp-content/advanced-cache.php</code>. Performance Optimisation did not replace that file. Page-cache drop-ins from two sources can conflict — use only one full-page cache solution.', 'performance-optimisation' );
						break;
					case 'wp_cache_disabled':
						$messages[] = __( '<code>WP_CACHE</code> is set to <code>false</code> in your configuration. The plugin did not change it. Set <code>WP_CACHE</code> to <code>true</code> in wp-config.php if you want WordPress to load advanced-cache drop-ins.', 'performance-optimisation' );
						break;
					case 'wp_config_fs':
					case 'wp_config_writable':
					case 'wp_config_read':
						$messages[] = __( 'Could not update wp-config.php (filesystem access or permissions). If you need <code>WP_CACHE</code>, add it manually or fix file permissions.', 'performance-optimisation' );
						break;
					case 'wp_config_write_failed':
						$messages[] = __( 'Failed to write wp-config.php. Please check file permissions.', 'performance-optimisation' );
						break;
					default:
						break;
				}
			}

			if ( empty( $messages ) ) {
				return;
			}

			$dismiss = wp_nonce_url(
				add_query_arg( 'wppo_dismiss', 'activation' ),
				'wppo_dismiss_notice',
				'_wpnonce'
			);

			// role="alert" + aria-live="assertive" so screen readers announce the
			// activation problems immediately (mirrors the Main notice fix, audit
			// #888 Part 1 / finding 12).
			echo '<div class="notice notice-warning" role="alert" aria-live="assertive"><p><strong>' . esc_html__( 'Performance Optimisation', 'performance-optimisation' ) . '</strong></p><ul style="list-style:disc;padding-left:1.25em;">';
			foreach ( array_unique( $messages ) as $html ) {
				echo '<li>' . wp_kses_post( $html ) . '</li>';
			}
			echo '</ul><p><a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss this notice', 'performance-optimisation' ) . '</a></p></div>';
		}

		/**
		 * Object-cache circuit-breaker warning — the drop-in auto-disabled.
		 *
		 * The breaker never disables silently: when the circuit is open (the
		 * drop-in parked itself after repeated Redis failures) this renders
		 * a notice-error with the trip reason, the trip time, a link to the
		 * Object Cache screen, and a dismiss link. Dismissal persists only
		 * for the current trip (tripped_at comparison) so the next trip
		 * automatically re-arms the notice.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private function maybe_object_cache_circuit_notice(): void {
			if ( ! class_exists( 'PerformanceOptimise\Inc\Object_Cache' ) ) {
				return;
			}

			try {
				$circuit = ( new Object_Cache() )->get_circuit_state();
			} catch ( \Throwable $e ) {
				unset( $e );
				return;
			}

			if ( empty( $circuit['open'] ) ) {
				return;
			}

			$tripped_at = isset( $circuit['tripped_at'] ) ? (int) $circuit['tripped_at'] : 0;
			$dismissed  = (int) get_option( Object_Cache::CIRCUIT_DISMISSED_OPTION, 0 );
			if ( $tripped_at > 0 && $dismissed === $tripped_at ) {
				return;
			}

			$dismiss = wp_nonce_url(
				add_query_arg( 'wppo_dismiss', 'object_cache_circuit' ),
				'wppo_dismiss_notice',
				'_wpnonce'
			);

			$reason = isset( $circuit['reason'] ) && '' !== $circuit['reason']
				? $circuit['reason']
				: __( 'Repeated Redis connection failures.', 'performance-optimisation' );

			$when = $tripped_at > 0
				/* translators: %s: date/time the circuit breaker tripped */
				? sprintf( __( 'Tripped on %s.', 'performance-optimisation' ), date_i18n( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ), $tripped_at ) )
				: '';

			echo '<div class="notice notice-error" role="alert" aria-live="assertive"><p><strong>' . esc_html__( 'Performance Optimisation — Object cache auto-disabled', 'performance-optimisation' ) . '</strong> — ';
			echo esc_html( $reason ) . ' ' . esc_html( $when ) . ' ';
			echo esc_html__( 'Redis will be re-checked automatically, or re-enable it manually once Redis recovers.', 'performance-optimisation' );
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=performance-optimisation' ) ) . '">' . esc_html__( 'Open settings', 'performance-optimisation' ) . '</a>';
			echo ' &middot; <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'performance-optimisation' ) . '</a>';
			echo '</p></div>';
		}

		/**
		 * LiteSpeed coexistence warning — when both plugins active in auto mode.
		 *
		 * In auto mode with LSCache active, WPPO pauses its file cache and
		 * minify/combine/defer optimizers to avoid double processing. Show a
		 * dismissible info notice linking to the LiteSpeed control.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private function maybe_litespeed_coexistence_notice(): void {
			if ( ! class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) ) {
				return;
			}

			if ( ! LiteSpeed_Integration::is_litespeed() || ! LiteSpeed_Integration::is_lscache_active() ) {
				return;
			}

			if ( LiteSpeed_Integration::get_mode() !== LiteSpeed_Integration::MODE_AUTO ) {
				return;
			}

			if ( LiteSpeed_Integration::effective_mode() !== LiteSpeed_Integration::MODE_LITESPEED ) {
				return;
			}

			// Dismissible per user.
			$user_id = get_current_user_id();
			if ( $user_id && get_user_meta( $user_id, 'wppo_litespeed_notice_dismissed', true ) ) {
				return;
			}

			$dismiss = wp_nonce_url(
				add_query_arg( 'wppo_dismiss', 'litespeed' ),
				'wppo_dismiss_notice',
				'_wpnonce'
			);

			echo '<div class="notice notice-warning is-dismissible" role="status" aria-live="polite"><p><strong>' . esc_html__( 'Performance Optimisation — LiteSpeed detected', 'performance-optimisation' ) . '</strong> — ';
			echo esc_html__( 'Both Performance Optimisation and LiteSpeed Cache are active. In Auto mode, file cache & minify/combine/defer are paused to avoid double processing.', 'performance-optimisation' ) . ' ';
			echo esc_html__( 'Choose the cache owner in Performance → File Optimisation → Network → LiteSpeed.', 'performance-optimisation' );
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=performance-optimisation' ) ) . '">' . esc_html__( 'Open settings', 'performance-optimisation' ) . '</a>';
			echo ' &middot; <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'performance-optimisation' ) . '</a>';
			echo '</p></div>';
		}

		/**
		 * Warn when another page-cache plugin is active alongside this one.
		 *
		 * @return void
		 */
		private function maybe_competing_plugins_notice(): void {
			if ( ! Advanced_Cache_Handler::is_our_dropin() ) {
				return;
			}

			$active = self::get_active_plugin_files();
			$found  = array();

			foreach ( self::COMPETING_CACHE_PLUGINS as $file => $label ) {
				if ( in_array( $file, $active, true ) ) {
					$found[ $file ] = $label;
				}
			}

			if ( empty( $found ) ) {
				return;
			}

			$names = implode( ', ', array_map( 'esc_html', $found ) );

			echo '<div class="notice notice-info is-dismissible" role="status" aria-live="polite"><p>';
			echo esc_html__( 'You have another page caching plugin active:', 'performance-optimisation' ) . ' ' . esc_html( $names ) . '. ';
			echo esc_html__( 'Running multiple full-page cache solutions can cause conflicts. Consider using only one.', 'performance-optimisation' );
			echo '</p></div>';
		}

		/**
		 * Success notice after a builder-update purge (issue #907).
		 *
		 * The watcher stages a transient with the purged builder labels; it
		 * is read and deleted here so the notice renders once, and expires
		 * via its TTL otherwise (no dismiss key needed).
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private function maybe_builder_purge_notice(): void {
			if ( ! class_exists( 'PerformanceOptimise\Inc\Builder_Purge_Watcher' ) ) {
				return;
			}

			$notice = get_transient( Util::transient_key( Builder_Purge_Watcher::NOTICE_TRANSIENT ) );

			if ( ! is_array( $notice ) || ! isset( $notice['builders'] ) || ! is_array( $notice['builders'] ) || empty( $notice['builders'] ) ) {
				return;
			}

			$labels = array();
			foreach ( $notice['builders'] as $label ) {
				$label = sanitize_text_field( wp_unslash( (string) $label ) );
				if ( '' !== $label ) {
					$labels[] = $label;
				}
			}

			if ( empty( $labels ) ) {
				return;
			}

			// Consume only once a valid notice is confirmed for render, so a
			// malformed transient is left for inspection instead of being
			// silently swallowed.
			delete_transient( Util::transient_key( Builder_Purge_Watcher::NOTICE_TRANSIENT ) );

			echo '<div class="notice notice-success is-dismissible" role="status" aria-live="polite"><p><strong>' . esc_html__( 'Performance Optimisation', 'performance-optimisation' ) . '</strong> — ';
			printf(
				/* translators: %s: comma-separated builder names */
				esc_html__( 'Builder update detected (%s). Page cache and builder CSS were purged.', 'performance-optimisation' ),
				esc_html( implode( ', ', $labels ) )
			);
			echo '</p></div>';
		}

		/**
		 * Display a gentle review request prompt after 7 days of active plugin use.
		 *
		 * @return void
		 */
		private function maybe_review_notice(): void {
			$screen = get_current_screen();

			// Limit the review ask to the plugin's own admin screen so it does
			// not nag on every wp-admin page (matches Main::admin_enqueue_scripts()).
			if ( ! $screen || 'toplevel_page_performance-optimisation' !== $screen->base ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( get_option( 'wppo_review_dismissed' ) ) {
				return;
			}

			$snoozed_until = (int) get_option( 'wppo_review_snoozed_until', 0 );
			if ( $snoozed_until > time() ) {
				return;
			}

			$activation_time = get_option( 'wppo_activation_time' );
			if ( false === $activation_time ) {
				update_option( 'wppo_activation_time', time() );
				return;
			}

			if ( ( time() - (int) $activation_time ) < ( 7 * DAY_IN_SECONDS ) ) {
				return;
			}

			$review_url = 'https://wordpress.org/support/plugin/performance-optimisation/reviews/#new-post';

			$dismiss_done_url = wp_nonce_url(
				add_query_arg( 'wppo_dismiss', 'review_done' ),
				'wppo_dismiss_notice',
				'_wpnonce'
			);

			$dismiss_snooze_url = wp_nonce_url(
				add_query_arg( 'wppo_dismiss', 'review_snooze' ),
				'wppo_dismiss_notice',
				'_wpnonce'
			);

			echo '<div class="notice notice-info is-dismissible wppo-review-notice" role="status" aria-live="polite"><p><strong>';
			echo esc_html__( 'Enjoying Performance Optimisation?', 'performance-optimisation' );
			echo '</strong> ';
			echo esc_html__( 'A quick review helps other WordPress users discover it.', 'performance-optimisation' );
			echo '</p><p>';
			echo '<a href="' . esc_url( $review_url ) . '" target="_blank" rel="noopener noreferrer" class="button button-primary">' . esc_html__( 'Leave a Review', 'performance-optimisation' ) . '</a> ';
			echo '<a href="' . esc_url( $dismiss_done_url ) . '" class="button button-secondary">' . esc_html__( 'Already did', 'performance-optimisation' ) . '</a> ';
			echo '<a href="' . esc_url( $dismiss_snooze_url ) . '" class="button button-secondary">' . esc_html__( 'Not now', 'performance-optimisation' ) . '</a>';
			echo '</p></div>';
		}

		/**
		 * Active plugin paths for the current site.
		 *
		 * @return string[]
		 */
		private static function get_active_plugin_files(): array {
			$plugins = (array) get_option( 'active_plugins', array() );

			if ( function_exists( 'is_multisite' ) ) {
				$is_multisite = false;
				try {
					$is_multisite = is_multisite();
				} catch ( \Throwable $e ) {
					$is_multisite = false;
				}
				if ( $is_multisite ) {
					try {
						$network = (array) get_site_option( 'active_sitewide_plugins', array() );
						$plugins = array_merge( $plugins, array_keys( $network ) );
					} catch ( \Throwable $e ) {
						unset( $e ); // Missing mock — treat as no network plugins.
					}
				}
			}

			return array_values( array_unique( $plugins ) );
		}
	}
}
