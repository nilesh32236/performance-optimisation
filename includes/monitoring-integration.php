<?php
/**
 * Monitoring System Integration
 *
 * This file integrates the intelligent monitoring system with the existing plugin
 *
 * @package PerformanceOptimisation
 * @since   2.1.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PerformanceOptimisation\Core\Bootstrap\IntelligentMonitoringBootstrap;

/**
 * Initialize the intelligent monitoring system
 */
function wppo_initialize_monitoring_system() {
	try {
		$monitoring = IntelligentMonitoringBootstrap::getInstance();
		$monitoring->initialize();

		// Add activation hook for monitoring
		add_action(
			'wppo_plugin_activated',
			function () use ( $monitoring ) {
				$monitoring->activate();
			}
		);

		// Add deactivation hook for monitoring
		add_action(
			'wppo_plugin_deactivated',
			function () use ( $monitoring ) {
				$monitoring->deactivate();
			}
		);

	} catch ( Exception $e ) {
		error_log( 'WPPO Monitoring Integration Error: ' . $e->getMessage() );
	}
}

// Hook into plugin initialization
add_action( 'wppo_after_plugin_init', 'wppo_initialize_monitoring_system', 10 );

/**
 * Add monitoring menu items to existing admin structure
 */
function wppo_add_monitoring_menus() {
	// This will be handled by the PerformanceMonitorAdmin class
	// but we can add a hook here for extensibility
	do_action( 'wppo_monitoring_menus_loaded' );
}
add_action( 'admin_menu', 'wppo_add_monitoring_menus', 15 );

/**
 * Add monitoring system info to plugin status
 */
function wppo_add_monitoring_status( $status ) {
	$monitoring           = IntelligentMonitoringBootstrap::getInstance();
	$status['monitoring'] = $monitoring->getStatus();
	return $status;
}
add_filter( 'wppo_system_status', 'wppo_add_monitoring_status' );

/**
 * Helper function to get monitoring services
 */
function wppo_get_monitoring_services() {
	$monitoring = IntelligentMonitoringBootstrap::getInstance();
	$provider   = $monitoring->getMonitoringProvider();

	if ( ! $provider ) {
		return null;
	}

	return array(
		'analytics' => $provider->getAnalyticsService(),
		'optimizer' => $provider->getOptimizerService(),
		'monitor'   => $provider->getMonitorService(),
	);
}

/**
 * Helper function to get analytics service
 */
function wppo_get_analytics_service() {
	$services = wppo_get_monitoring_services();
	return $services ? $services['analytics'] : null;
}

/**
 * Helper function to get optimizer service
 */
function wppo_get_optimizer_service() {
	$services = wppo_get_monitoring_services();
	return $services ? $services['optimizer'] : null;
}

/**
 * Helper function to get monitor service
 */
function wppo_get_monitor_service() {
	$services = wppo_get_monitoring_services();
	return $services ? $services['monitor'] : null;
}

/**
 * Add monitoring system CSS to admin pages
 */
function wppo_add_monitoring_admin_styles() {
	$screen = get_current_screen();

	// Add styles to all performance optimization admin pages
	if ( $screen && strpos( $screen->id, 'performance-optimisation' ) !== false ) {
		wp_add_inline_style(
			'wp-admin',
			'
			.wppo-monitoring-badge {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 12px;
				font-size: 11px;
				font-weight: 600;
				text-transform: uppercase;
				letter-spacing: 0.5px;
			}
			.wppo-monitoring-badge.active {
				background: #46b450;
				color: #fff;
			}
			.wppo-monitoring-badge.inactive {
				background: #dc3232;
				color: #fff;
			}
			.wppo-quick-stats {
				background: #f8f9fa;
				border: 1px solid #e0e0e0;
				border-radius: 6px;
				padding: 12px;
				margin: 12px 0;
			}
			.wppo-quick-stats h4 {
				margin: 0 0 8px 0;
				font-size: 14px;
				color: #1d2327;
			}
			.wppo-quick-stats .stats-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
				gap: 12px;
			}
			.wppo-quick-stats .stat-item {
				text-align: center;
			}
			.wppo-quick-stats .stat-value {
				font-size: 18px;
				font-weight: 600;
				color: #007cba;
			}
			.wppo-quick-stats .stat-label {
				font-size: 11px;
				color: #646970;
				text-transform: uppercase;
				letter-spacing: 0.5px;
			}
		'
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wppo_add_monitoring_admin_styles' );

/**
 * Add monitoring quick stats to existing admin pages
 */
function wppo_add_monitoring_quick_stats() {
	$screen = get_current_screen();

	// Only show on main performance optimization pages
	if ( ! $screen || strpos( $screen->id, 'performance-optimisation' ) === false ) {
		return;
	}

	// Don't show on monitoring pages themselves
	if ( strpos( $screen->id, 'wppo-monitor' ) !== false || strpos( $screen->id, 'wppo-optimizer' ) !== false ) {
		return;
	}

	$analytics = wppo_get_analytics_service();
	if ( ! $analytics ) {
		return;
	}

	try {
		$performance_score = $analytics->getPerformanceScore();
		$cache_hit_rate    = $analytics->getCacheHitRate();
		$avg_load_time     = $analytics->getAverageLoadTime( 1 );

		?>
		<div class="wppo-quick-stats">
			<h4>
				<?php _e( 'Performance Overview', 'performance-optimisation' ); ?>
				<span class="wppo-monitoring-badge active"><?php _e( 'Live', 'performance-optimisation' ); ?></span>
			</h4>
			<div class="stats-grid">
				<div class="stat-item">
					<div class="stat-value"><?php echo esc_html( $performance_score ); ?></div>
					<div class="stat-label"><?php _e( 'Score', 'performance-optimisation' ); ?></div>
				</div>
				<div class="stat-item">
					<div class="stat-value"><?php echo esc_html( number_format( $cache_hit_rate, 1 ) ); ?>%</div>
					<div class="stat-label"><?php _e( 'Cache Hit', 'performance-optimisation' ); ?></div>
				</div>
				<div class="stat-item">
					<div class="stat-value"><?php echo esc_html( number_format( $avg_load_time, 2 ) ); ?>s</div>
					<div class="stat-label"><?php _e( 'Load Time', 'performance-optimisation' ); ?></div>
				</div>
				<div class="stat-item">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wppo-monitor' ) ); ?>" class="button button-small">
						<?php _e( 'View Details', 'performance-optimisation' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php

	} catch ( Exception $e ) {
		// Silently fail if monitoring data is not available
		error_log( 'WPPO: Failed to display quick stats: ' . $e->getMessage() );
	}
}

/**
 * Add monitoring info to plugin footer
 */
function wppo_add_monitoring_footer_info() {
	$screen = get_current_screen();

	if ( $screen && strpos( $screen->id, 'performance-optimisation' ) !== false ) {
		$monitoring = IntelligentMonitoringBootstrap::getInstance();
		$status     = $monitoring->getStatus();

		?>
		<script>
		jQuery(document).ready(function($) {
			var monitoringStatus = <?php echo wp_json_encode( $status ); ?>;
			
			// Add monitoring status to footer
			$('#wpfooter').append(
				'<p style="float: right; margin-right: 20px; font-size: 11px; color: #646970;">' +
				'Monitoring: ' + (monitoringStatus.enabled ? 
					'<span style="color: #46b450;">Active</span>' : 
					'<span style="color: #dc3232;">Inactive</span>') +
				'</p>'
			);
		});
		</script>
		<?php
	}
}
add_action( 'admin_footer', 'wppo_add_monitoring_footer_info' );

/**
 * Add monitoring system to plugin health check
 */
function wppo_monitoring_health_check( $tests ) {
	$tests['direct']['wppo_monitoring'] = array(
		'label' => __( 'Performance Monitoring System', 'performance-optimisation' ),
		'test'  => 'wppo_test_monitoring_system',
	);

	return $tests;
}
add_filter( 'site_status_tests', 'wppo_monitoring_health_check' );

/**
 * Test monitoring system health
 */
function wppo_test_monitoring_system() {
	$monitoring = IntelligentMonitoringBootstrap::getInstance();
	$status     = $monitoring->getStatus();

	$result = array(
		'label'       => __( 'Performance Monitoring System', 'performance-optimisation' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Performance', 'performance-optimisation' ),
			'color' => 'blue',
		),
		'description' => '',
		'actions'     => '',
		'test'        => 'wppo_monitoring_system',
	);

	if ( ! $status['enabled'] ) {
		$result['status']      = 'recommended';
		$result['description'] = __( 'Performance monitoring is disabled. Enable it to get insights into your site performance.', 'performance-optimisation' );
	} elseif ( ! $status['initialized'] ) {
		$result['status']      = 'critical';
		$result['description'] = __( 'Performance monitoring system failed to initialize. Check error logs for details.', 'performance-optimisation' );
	} elseif ( ! $status['tables_exist'] ) {
		$result['status']      = 'critical';
		$result['description'] = __( 'Performance monitoring database tables are missing. Try deactivating and reactivating the plugin.', 'performance-optimisation' );
	} else {
		$result['description'] = __( 'Performance monitoring system is working correctly.', 'performance-optimisation' );
	}

	return $result;
}

// Initialize monitoring integration
if ( ! defined( 'WPPO_DISABLE_MONITORING' ) || ! WPPO_DISABLE_MONITORING ) {
	// Hook to show quick stats on admin pages
	add_action( 'wppo_admin_page_header', 'wppo_add_monitoring_quick_stats' );
}
