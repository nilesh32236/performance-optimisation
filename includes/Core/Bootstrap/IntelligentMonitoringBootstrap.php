<?php
/**
 * Intelligent Monitoring Bootstrap
 *
 * @package PerformanceOptimisation\Core\Bootstrap
 * @since   2.1.0
 */

namespace PerformanceOptimisation\Core\Bootstrap;

use PerformanceOptimisation\Providers\IntelligentMonitoringServiceProvider;

/**
 * Intelligent Monitoring Bootstrap Class
 *
 * Integrates the intelligent monitoring system with the existing plugin
 */
class IntelligentMonitoringBootstrap {

	private static ?IntelligentMonitoringBootstrap $instance           = null;
	private ?IntelligentMonitoringServiceProvider $monitoring_provider = null;
	private bool $initialized = false;

	/**
	 * Get singleton instance
	 */
	public static function getInstance(): IntelligentMonitoringBootstrap {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to prevent direct instantiation
	 */
	private function __construct() {
		// Private constructor for singleton
	}

	/**
	 * Initialize the intelligent monitoring system
	 */
	public function initialize(): void {
		if ( $this->initialized ) {
			return;
		}

		// Check if monitoring is enabled
		if ( ! $this->isMonitoringEnabled() ) {
			return;
		}

		try {
			// Initialize the monitoring service provider
			$this->monitoring_provider = new IntelligentMonitoringServiceProvider();

			// Register activation hooks
			$this->registerActivationHooks();

			// Register admin bar styles
			$this->registerAdminBarStyles();

			// Add monitoring status to plugin info
			$this->addPluginInfo();

			$this->initialized = true;

			// Log successful initialization
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WPPO: Intelligent Monitoring System initialized successfully' );
			}
		} catch ( Exception $e ) {
			// Log initialization error
			error_log( 'WPPO: Failed to initialize Intelligent Monitoring System: ' . $e->getMessage() );

			// Show admin notice for initialization failure
			add_action(
				'admin_notices',
				function () use ( $e ) {
					printf(
						'<div class="notice notice-warning"><p>%s</p></div>',
						sprintf(
							__( 'Performance Optimisation: Monitoring system initialization failed: %s', 'performance-optimisation' ),
							esc_html( $e->getMessage() )
						)
					);
				}
			);
		}
	}

	/**
	 * Handle plugin activation for monitoring system
	 */
	public function activate(): void {
		try {
			// Create database tables
			do_action( 'wppo_create_tables' );

			// Set default monitoring options
			$this->setDefaultOptions();

			// Schedule monitoring tasks
			$this->scheduleMonitoringTasks();

			// Flush rewrite rules if needed
			flush_rewrite_rules();

		} catch ( Exception $e ) {
			error_log( 'WPPO: Monitoring system activation failed: ' . $e->getMessage() );
			throw $e;
		}
	}

	/**
	 * Handle plugin deactivation for monitoring system
	 */
	public function deactivate(): void {
		try {
			// Clear scheduled monitoring tasks
			$this->clearScheduledTasks();

			// Optionally preserve data (don't delete tables on deactivation)
			// Tables will be cleaned up on uninstall if needed

		} catch ( Exception $e ) {
			error_log( 'WPPO: Monitoring system deactivation failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Get the monitoring service provider
	 */
	public function getMonitoringProvider(): ?IntelligentMonitoringServiceProvider {
		return $this->monitoring_provider;
	}

	/**
	 * Check if monitoring is enabled
	 */
	private function isMonitoringEnabled(): bool {
		// Check if explicitly disabled
		if ( defined( 'WPPO_DISABLE_MONITORING' ) && WPPO_DISABLE_MONITORING ) {
			return false;
		}

		// Check option setting
		return get_option( 'wppo_monitoring_enabled', true );
	}

	/**
	 * Register activation hooks for monitoring
	 */
	private function registerActivationHooks(): void {
		// Hook into existing plugin activation
		add_action( 'wppo_plugin_activated', array( $this, 'activate' ) );
		add_action( 'wppo_plugin_deactivated', array( $this, 'deactivate' ) );

		// Create tables on first load if they don't exist
		add_action( 'init', array( $this, 'maybeCreateTables' ), 5 );
	}

	/**
	 * Maybe create database tables if they don't exist
	 */
	public function maybeCreateTables(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wppo_performance_stats';

		// Check if table exists
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		) === $table_name;

		if ( ! $table_exists ) {
			do_action( 'wppo_create_tables' );
		}
	}

	/**
	 * Register admin bar styles
	 */
	private function registerAdminBarStyles(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueAdminBarStyles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminBarStyles' ) );
	}

	/**
	 * Enqueue admin bar styles
	 */
	public function enqueueAdminBarStyles(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		wp_add_inline_style(
			'admin-bar',
			'
			.wppo-admin-bar-score {
				display: inline-block;
				padding: 2px 6px;
				border-radius: 3px;
				font-weight: bold;
				font-size: 12px;
			}
			.wppo-score-excellent { background: #46b450; color: #fff; }
			.wppo-score-good { background: #ffb900; color: #fff; }
			.wppo-score-fair { background: #ff8c00; color: #fff; }
			.wppo-score-poor { background: #dc3232; color: #fff; }
		'
		);
	}

	/**
	 * Add plugin info for monitoring
	 */
	private function addPluginInfo(): void {
		add_filter( 'wppo_plugin_info', array( $this, 'addMonitoringInfo' ) );
	}

	/**
	 * Add monitoring information to plugin info
	 */
	public function addMonitoringInfo( array $info ): array {
		if ( $this->monitoring_provider ) {
			$analytics = $this->monitoring_provider->getAnalyticsService();

			$info['monitoring'] = array(
				'enabled'           => true,
				'performance_score' => $analytics->getPerformanceScore(),
				'cache_hit_rate'    => $analytics->getCacheHitRate(),
				'last_analysis'     => get_option( 'wppo_last_analysis', null ),
			);
		} else {
			$info['monitoring'] = array(
				'enabled' => false,
				'reason'  => 'Monitoring system not initialized',
			);
		}

		return $info;
	}

	/**
	 * Set default monitoring options
	 */
	private function setDefaultOptions(): void {
		$defaults = array(
			'wppo_monitoring_enabled'    => true,
			'wppo_sample_rate'           => 0.1, // 10% sampling
			'wppo_email_notifications'   => false,
			'wppo_data_retention_days'   => 30,
			'wppo_cache_retention_hours' => 24,
			'wppo_alert_thresholds'      => array(
				'load_time'      => 3.0,
				'memory_usage'   => 128 * 1024 * 1024, // 128MB
				'db_queries'     => 100,
				'error_rate'     => 0.05,
				'cache_hit_rate' => 60,
			),
		);

		foreach ( $defaults as $option => $value ) {
			if ( get_option( $option ) === false ) {
				update_option( $option, $value );
			}
		}
	}

	/**
	 * Schedule monitoring tasks
	 */
	private function scheduleMonitoringTasks(): void {
		// Daily performance analysis
		if ( ! wp_next_scheduled( 'wppo_daily_performance_analysis' ) ) {
			wp_schedule_event( time(), 'daily', 'wppo_daily_performance_analysis' );
		}

		// Weekly data cleanup
		if ( ! wp_next_scheduled( 'wppo_cleanup_old_data' ) ) {
			wp_schedule_event( time(), 'weekly', 'wppo_cleanup_old_data' );
		}

		// Performance check every 5 minutes (if enabled)
		$frequent_checks = get_option( 'wppo_frequent_checks', false );
		if ( $frequent_checks && ! wp_next_scheduled( 'wppo_performance_check' ) ) {
			wp_schedule_event( time(), 'every_five_minutes', 'wppo_performance_check' );
		}
	}

	/**
	 * Clear scheduled monitoring tasks
	 */
	private function clearScheduledTasks(): void {
		$scheduled_hooks = array(
			'wppo_daily_performance_analysis',
			'wppo_cleanup_old_data',
			'wppo_performance_check',
		);

		foreach ( $scheduled_hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}

	/**
	 * Get monitoring system status
	 */
	public function getStatus(): array {
		return array(
			'initialized'     => $this->initialized,
			'enabled'         => $this->isMonitoringEnabled(),
			'provider_loaded' => $this->monitoring_provider !== null,
			'tables_exist'    => $this->tablesExist(),
			'scheduled_tasks' => $this->getScheduledTasks(),
		);
	}

	/**
	 * Check if monitoring tables exist
	 */
	private function tablesExist(): bool {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'wppo_performance_stats',
			$wpdb->prefix . 'wppo_performance_alerts',
		);

		foreach ( $tables as $table ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SHOW TABLES LIKE %s',
					$table
				)
			) === $table;

			if ( ! $exists ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get scheduled monitoring tasks
	 */
	private function getScheduledTasks(): array {
		$tasks = array();

		$scheduled_hooks = array(
			'wppo_daily_performance_analysis' => 'Daily Analysis',
			'wppo_cleanup_old_data'           => 'Data Cleanup',
			'wppo_performance_check'          => 'Performance Check',
		);

		foreach ( $scheduled_hooks as $hook => $name ) {
			$timestamp      = wp_next_scheduled( $hook );
			$tasks[ $hook ] = array(
				'name'      => $name,
				'scheduled' => $timestamp !== false,
				'next_run'  => $timestamp ? date( 'Y-m-d H:i:s', $timestamp ) : null,
			);
		}

		return $tasks;
	}

	/**
	 * Enable monitoring system
	 */
	public function enable(): bool {
		try {
			update_option( 'wppo_monitoring_enabled', true );

			if ( ! $this->initialized ) {
				$this->initialize();
			}

			// Reschedule tasks
			$this->scheduleMonitoringTasks();

			return true;
		} catch ( Exception $e ) {
			error_log( 'WPPO: Failed to enable monitoring: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Disable monitoring system
	 */
	public function disable(): bool {
		try {
			update_option( 'wppo_monitoring_enabled', false );

			// Clear scheduled tasks
			$this->clearScheduledTasks();

			return true;
		} catch ( Exception $e ) {
			error_log( 'WPPO: Failed to disable monitoring: ' . $e->getMessage() );
			return false;
		}
	}
}
