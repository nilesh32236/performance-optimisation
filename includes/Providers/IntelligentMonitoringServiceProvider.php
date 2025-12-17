<?php
/**
 * Intelligent Monitoring Service Provider
 *
 * @package PerformanceOptimisation\Providers
 * @since   2.1.0
 */

namespace PerformanceOptimisation\Providers;

use PerformanceOptimisation\Services\AnalyticsService;
use PerformanceOptimisation\Services\IntelligentOptimizationService;
use PerformanceOptimisation\Services\PerformanceMonitorService;
use PerformanceOptimisation\Admin\PerformanceMonitorAdmin;

/**
 * Intelligent Monitoring Service Provider Class
 */
class IntelligentMonitoringServiceProvider {

	private AnalyticsService $analytics;
	private IntelligentOptimizationService $optimizer;
	private PerformanceMonitorService $monitor;
	private PerformanceMonitorAdmin $admin;

	public function __construct() {
		$this->initializeServices();
		$this->registerHooks();
	}

	/**
	 * Initialize all monitoring services
	 */
	private function initializeServices(): void {
		// Initialize analytics service (existing)
		$this->analytics = new AnalyticsService();

		// Initialize intelligent optimization service
		$this->optimizer = new IntelligentOptimizationService( $this->analytics );

		// Initialize performance monitor service
		$this->monitor = new PerformanceMonitorService( $this->analytics, $this->optimizer );

		// Initialize admin interface
		if ( is_admin() ) {
			$this->admin = new PerformanceMonitorAdmin(
				$this->monitor,
				$this->optimizer,
				$this->analytics
			);
		}
	}

	/**
	 * Register WordPress hooks
	 */
	private function registerHooks(): void {
		// Start real-time monitoring
		add_action( 'init', array( $this, 'startMonitoring' ) );

		// Create database tables
		add_action( 'wppo_create_tables', array( $this, 'createDatabaseTables' ) );

		// Schedule performance analysis
		add_action( 'wp', array( $this, 'schedulePerformanceAnalysis' ) );

		// Add custom cron intervals
		add_filter( 'cron_schedules', array( $this, 'addCustomCronIntervals' ) );

		// Performance analysis hook
		add_action( 'wppo_daily_performance_analysis', array( $this, 'performDailyAnalysis' ) );

		// Cleanup old data
		add_action( 'wppo_cleanup_old_data', array( $this, 'cleanupOldData' ) );

		// Add admin bar monitoring
		add_action( 'admin_bar_menu', array( $this, 'addAdminBarMonitoring' ), 100 );

		// Enqueue frontend monitoring scripts
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueFrontendScripts' ) );
	}

	/**
	 * Start performance monitoring
	 */
	public function startMonitoring(): void {
		if ( ! $this->shouldStartMonitoring() ) {
			return;
		}

		$this->monitor->startRealTimeMonitoring();
	}

	/**
	 * Create database tables for monitoring
	 */
	public function createDatabaseTables(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wppo_performance_stats';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			metric_name varchar(50) NOT NULL,
			metric_value longtext NOT NULL,
			recorded_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY metric_name (metric_name),
			KEY recorded_at (recorded_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Create alerts table
		$alerts_table = $wpdb->prefix . 'wppo_performance_alerts';

		$alerts_sql = "CREATE TABLE $alerts_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			alert_type varchar(50) NOT NULL,
			severity varchar(20) NOT NULL,
			message text NOT NULL,
			alert_data longtext,
			is_resolved tinyint(1) DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			resolved_at datetime NULL,
			PRIMARY KEY (id),
			KEY alert_type (alert_type),
			KEY severity (severity),
			KEY is_resolved (is_resolved),
			KEY created_at (created_at)
		) $charset_collate;";

		dbDelta( $alerts_sql );
	}

	/**
	 * Schedule performance analysis
	 */
	public function schedulePerformanceAnalysis(): void {
		// Daily performance analysis
		if ( ! wp_next_scheduled( 'wppo_daily_performance_analysis' ) ) {
			wp_schedule_event( time(), 'daily', 'wppo_daily_performance_analysis' );
		}

		// Weekly data cleanup
		if ( ! wp_next_scheduled( 'wppo_cleanup_old_data' ) ) {
			wp_schedule_event( time(), 'weekly', 'wppo_cleanup_old_data' );
		}
	}

	/**
	 * Add custom cron intervals
	 */
	public function addCustomCronIntervals( array $schedules ): array {
		$schedules['every_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every Minute', 'performance-optimisation' ),
		);

		$schedules['every_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 Minutes', 'performance-optimisation' ),
		);

		return $schedules;
	}

	/**
	 * Perform daily performance analysis
	 */
	public function performDailyAnalysis(): void {
		try {
			// Run site analysis
			$analysis = $this->optimizer->analyzeSite();

			// Store analysis results
			$this->storeAnalysisResults( $analysis );

			// Generate alerts for critical issues
			$this->generateCriticalAlerts( $analysis );

			// Send email notifications if configured
			$this->sendPerformanceNotifications( $analysis );

		} catch ( Exception $e ) {
			error_log( 'WPPO Daily Analysis Error: ' . $e->getMessage() );
		}
	}

	/**
	 * Cleanup old performance data
	 */
	public function cleanupOldData(): void {
		global $wpdb;

		$retention_days = apply_filters( 'wppo_data_retention_days', 30 );

		// Clean performance stats older than retention period
		$stats_table = $wpdb->prefix . 'wppo_performance_stats';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $stats_table WHERE recorded_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$retention_days
			)
		);

		// Clean resolved alerts older than 7 days
		$alerts_table = $wpdb->prefix . 'wppo_performance_alerts';
		$wpdb->query(
			"DELETE FROM $alerts_table 
			 WHERE is_resolved = 1 
			 AND resolved_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
		);

		// Clean up cache files
		$this->cleanupCacheFiles();
	}

	/**
	 * Add admin bar monitoring
	 */
	public function addAdminBarMonitoring( $wp_admin_bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$performance_score = $this->analytics->getPerformanceScore();
		$status_class      = $this->getStatusClass( $performance_score );

		$wp_admin_bar->add_node(
			array(
				'id'    => 'wppo-monitor',
				'title' => sprintf(
					'<span class="wppo-admin-bar-score wppo-score-%s">⚡ %d</span>',
					$status_class,
					$performance_score
				),
				'href'  => admin_url( 'admin.php?page=wppo-monitor' ),
				'meta'  => array(
					'title' => sprintf(
						__( 'Performance Score: %d - Click to view details', 'performance-optimisation' ),
						$performance_score
					),
				),
			)
		);

		// Add quick actions submenu
		$wp_admin_bar->add_node(
			array(
				'parent' => 'wppo-monitor',
				'id'     => 'wppo-clear-cache',
				'title'  => __( 'Clear Cache', 'performance-optimisation' ),
				'href'   => wp_nonce_url(
					admin_url( 'admin.php?page=wppo-monitor&action=clear_cache' ),
					'wppo_clear_cache'
				),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'parent' => 'wppo-monitor',
				'id'     => 'wppo-run-analysis',
				'title'  => __( 'Run Analysis', 'performance-optimisation' ),
				'href'   => wp_nonce_url(
					admin_url( 'admin.php?page=wppo-optimizer&action=run_analysis' ),
					'wppo_run_analysis'
				),
			)
		);
	}

	/**
	 * Enqueue frontend monitoring scripts
	 */
	public function enqueueFrontendScripts(): void {
		if ( is_admin() || ! $this->shouldTrackFrontend() ) {
			return;
		}

		wp_enqueue_script(
			'wppo-frontend-monitor',
			plugins_url( 'assets/js/frontend-monitor.js', dirname( __DIR__ ) ),
			array(),
			'2.1.0',
			true
		);

		wp_localize_script(
			'wppo-frontend-monitor',
			'wppoAjax',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wppo_monitor_nonce' ),
			)
		);
	}

	/**
	 * Get services for external access
	 */
	public function getAnalyticsService(): AnalyticsService {
		return $this->analytics;
	}

	public function getOptimizerService(): IntelligentOptimizationService {
		return $this->optimizer;
	}

	public function getMonitorService(): PerformanceMonitorService {
		return $this->monitor;
	}

	/**
	 * Check if monitoring should start
	 */
	private function shouldStartMonitoring(): bool {
		// Don't monitor during maintenance
		if ( defined( 'WP_MAINTENANCE_MODE' ) && WP_MAINTENANCE_MODE ) {
			return false;
		}

		// Don't monitor CLI requests
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		// Check if monitoring is enabled
		$monitoring_enabled = get_option( 'wppo_monitoring_enabled', true );
		if ( ! $monitoring_enabled ) {
			return false;
		}

		return true;
	}

	/**
	 * Store analysis results
	 */
	private function storeAnalysisResults( array $analysis ): void {
		update_option(
			'wppo_last_analysis',
			array(
				'timestamp' => current_time( 'mysql' ),
				'results'   => $analysis,
			)
		);
	}

	/**
	 * Generate critical alerts
	 */
	private function generateCriticalAlerts( array $analysis ): void {
		global $wpdb;

		$alerts_table = $wpdb->prefix . 'wppo_performance_alerts';

		// Check for critical performance issues
		if ( $analysis['performance_score'] < 50 ) {
			$wpdb->insert(
				$alerts_table,
				array(
					'alert_type' => 'critical_performance',
					'severity'   => 'critical',
					'message'    => sprintf(
						__( 'Critical performance issue detected. Score: %d', 'performance-optimisation' ),
						$analysis['performance_score']
					),
					'alert_data' => wp_json_encode( $analysis ),
				)
			);
		}

		// Check for cache issues
		if ( $analysis['cache_analysis']['hit_rate'] < 50 ) {
			$wpdb->insert(
				$alerts_table,
				array(
					'alert_type' => 'cache_performance',
					'severity'   => 'warning',
					'message'    => sprintf(
						__( 'Low cache hit rate detected: %.1f%%', 'performance-optimisation' ),
						$analysis['cache_analysis']['hit_rate']
					),
					'alert_data' => wp_json_encode( $analysis['cache_analysis'] ),
				)
			);
		}
	}

	/**
	 * Send performance notifications
	 */
	private function sendPerformanceNotifications( array $analysis ): void {
		$notifications_enabled = get_option( 'wppo_email_notifications', false );
		if ( ! $notifications_enabled ) {
			return;
		}

		$admin_email = get_option( 'admin_email' );
		$site_name   = get_bloginfo( 'name' );

		// Only send if there are critical issues
		if ( $analysis['performance_score'] < 60 ) {
			$subject = sprintf(
				__( '[%1$s] Performance Alert - Score: %2$d', 'performance-optimisation' ),
				$site_name,
				$analysis['performance_score']
			);

			$message = $this->generateEmailMessage( $analysis );

			wp_mail( $admin_email, $subject, $message );
		}
	}

	/**
	 * Generate email message for notifications
	 */
	private function generateEmailMessage( array $analysis ): string {
		$message = sprintf(
			__( "Performance analysis completed for %s\n\n", 'performance-optimisation' ),
			get_bloginfo( 'name' )
		);

		$message .= sprintf(
			__( "Performance Score: %d\n", 'performance-optimisation' ),
			$analysis['performance_score']
		);

		$message .= sprintf(
			__( "Cache Hit Rate: %.1f%%\n", 'performance-optimisation' ),
			$analysis['cache_analysis']['hit_rate']
		);

		if ( ! empty( $analysis['recommendations'] ) ) {
			$message .= __( "\nTop Recommendations:\n", 'performance-optimisation' );

			foreach ( array_slice( $analysis['recommendations'], 0, 3 ) as $rec ) {
				$message .= sprintf( "- %s\n", $rec['title'] );
			}
		}

		$message .= sprintf(
			__( "\nView full report: %s\n", 'performance-optimisation' ),
			admin_url( 'admin.php?page=wppo-monitor' )
		);

		return $message;
	}

	/**
	 * Cleanup cache files
	 */
	private function cleanupCacheFiles(): void {
		$cache_dir = WP_CONTENT_DIR . '/cache/wppo/';
		if ( ! is_dir( $cache_dir ) ) {
			return;
		}

		$retention_hours = apply_filters( 'wppo_cache_retention_hours', 24 );
		$cutoff_time     = time() - ( $retention_hours * 3600 );

		$this->cleanupDirectory( $cache_dir, $cutoff_time );
	}

	/**
	 * Recursively cleanup directory
	 */
	private function cleanupDirectory( string $dir, int $cutoff_time ): void {
		$files = glob( $dir . '/*' );

		foreach ( $files as $file ) {
			if ( is_file( $file ) && filemtime( $file ) < $cutoff_time ) {
				unlink( $file );
			} elseif ( is_dir( $file ) ) {
				$this->cleanupDirectory( $file, $cutoff_time );

				// Remove empty directories
				if ( count( glob( $file . '/*' ) ) === 0 ) {
					rmdir( $file );
				}
			}
		}
	}

	/**
	 * Get status class for admin bar
	 */
	private function getStatusClass( int $score ): string {
		if ( $score >= 90 ) {
			return 'excellent';
		}
		if ( $score >= 80 ) {
			return 'good';
		}
		if ( $score >= 60 ) {
			return 'fair';
		}
		return 'poor';
	}

	/**
	 * Check if frontend tracking should be enabled
	 */
	private function shouldTrackFrontend(): bool {
		// Don't track admin pages
		if ( is_admin() ) {
			return false;
		}

		// Check if user opted out
		if ( isset( $_COOKIE['wppo_no_track'] ) ) {
			return false;
		}

		// Sample rate check
		$sample_rate = get_option( 'wppo_sample_rate', 0.1 );
		if ( mt_rand() / mt_getrandmax() > $sample_rate ) {
			return false;
		}

		return true;
	}
}
