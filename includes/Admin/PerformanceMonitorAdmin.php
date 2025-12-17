<?php
/**
 * Performance Monitor Admin Interface
 *
 * @package PerformanceOptimisation\Admin
 * @since   2.1.0
 */

namespace PerformanceOptimisation\Admin;

use PerformanceOptimisation\Services\PerformanceMonitorService;
use PerformanceOptimisation\Services\IntelligentOptimizationService;
use PerformanceOptimisation\Services\AnalyticsService;

/**
 * Performance Monitor Admin Class
 */
class PerformanceMonitorAdmin {

	private PerformanceMonitorService $monitor;
	private IntelligentOptimizationService $optimizer;
	private AnalyticsService $analytics;

	public function __construct(
		PerformanceMonitorService $monitor,
		IntelligentOptimizationService $optimizer,
		AnalyticsService $analytics
	) {
		$this->monitor   = $monitor;
		$this->optimizer = $optimizer;
		$this->analytics = $analytics;

		$this->initHooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function initHooks(): void {
		add_action( 'admin_menu', array( $this, 'addAdminMenus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'wp_ajax_wppo_get_dashboard_data', array( $this, 'ajaxGetDashboardData' ) );
		add_action( 'wp_ajax_wppo_apply_optimization', array( $this, 'ajaxApplyOptimization' ) );
		add_action( 'wp_ajax_wppo_track_metric', array( $this, 'ajaxTrackMetric' ) );
		add_action( 'wp_ajax_nopriv_wppo_track_metric', array( $this, 'ajaxTrackMetric' ) );
	}

	/**
	 * Add admin menu pages
	 */
	public function addAdminMenus(): void {
		add_submenu_page(
			'performance-optimisation',
			__( 'Performance Monitor', 'performance-optimisation' ),
			__( 'Monitor', 'performance-optimisation' ),
			'manage_options',
			'wppo-monitor',
			array( $this, 'renderMonitorPage' )
		);

		add_submenu_page(
			'performance-optimisation',
			__( 'Intelligent Optimizer', 'performance-optimisation' ),
			__( 'Optimizer', 'performance-optimisation' ),
			'manage_options',
			'wppo-optimizer',
			array( $this, 'renderOptimizerPage' )
		);
	}

	/**
	 * Enqueue admin assets
	 */
	public function enqueueAssets( string $hook ): void {
		if ( ! in_array( $hook, array( 'performance-optimisation_page_wppo-monitor', 'performance-optimisation_page_wppo-optimizer' ) ) ) {
			return;
		}

		wp_enqueue_script(
			'wppo-monitor-admin',
			plugins_url( 'assets/js/monitor-admin.js', dirname( __DIR__ ) ),
			array( 'jquery', 'wp-api-fetch' ),
			'2.1.0',
			true
		);

		wp_enqueue_style(
			'wppo-monitor-admin',
			plugins_url( 'assets/css/monitor-admin.css', dirname( __DIR__ ) ),
			array(),
			'2.1.0'
		);

		wp_localize_script(
			'wppo-monitor-admin',
			'wppoMonitor',
			array(
				'ajaxurl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'wppo_monitor_nonce' ),
				'refreshInterval' => 30000, // 30 seconds
				'strings'         => array(
					'loading' => __( 'Loading...', 'performance-optimisation' ),
					'error'   => __( 'Error loading data', 'performance-optimisation' ),
					'success' => __( 'Optimization applied successfully', 'performance-optimisation' ),
					'confirm' => __( 'Are you sure you want to apply this optimization?', 'performance-optimisation' ),
				),
			)
		);
	}

	/**
	 * Render performance monitor page
	 */
	public function renderMonitorPage(): void {
		?>
		<div class="wrap wppo-monitor-page">
			<h1><?php _e( 'Performance Monitor', 'performance-optimisation' ); ?></h1>
			
			<div id="wppo-dashboard-loading" class="wppo-loading">
				<div class="spinner is-active"></div>
				<p><?php _e( 'Loading performance data...', 'performance-optimisation' ); ?></p>
			</div>

			<div id="wppo-dashboard" style="display: none;">
				<!-- Performance Overview -->
				<div class="wppo-dashboard-section wppo-overview">
					<h2><?php _e( 'Performance Overview', 'performance-optimisation' ); ?></h2>
					<div class="wppo-metrics-grid">
						<div class="wppo-metric-card wppo-score-card">
							<div class="wppo-metric-header">
								<h3><?php _e( 'Performance Score', 'performance-optimisation' ); ?></h3>
								<div class="wppo-score-badge" id="performance-score">--</div>
							</div>
							<div class="wppo-metric-trend" id="performance-trend">
								<span class="wppo-trend-indicator"></span>
								<span class="wppo-trend-text"></span>
							</div>
						</div>

						<div class="wppo-metric-card">
							<h3><?php _e( 'Page Load Time', 'performance-optimisation' ); ?></h3>
							<div class="wppo-metric-value" id="load-time">--</div>
							<div class="wppo-metric-unit">seconds</div>
						</div>

						<div class="wppo-metric-card">
							<h3><?php _e( 'Cache Hit Rate', 'performance-optimisation' ); ?></h3>
							<div class="wppo-metric-value" id="cache-hit-rate">--</div>
							<div class="wppo-metric-unit">%</div>
						</div>

						<div class="wppo-metric-card">
							<h3><?php _e( 'Server Response', 'performance-optimisation' ); ?></h3>
							<div class="wppo-metric-value" id="server-response">--</div>
							<div class="wppo-metric-unit">ms</div>
						</div>
					</div>
				</div>

				<!-- Core Web Vitals -->
				<div class="wppo-dashboard-section wppo-core-vitals">
					<h2><?php _e( 'Core Web Vitals', 'performance-optimisation' ); ?></h2>
					<div class="wppo-vitals-grid">
						<div class="wppo-vital-card">
							<h4><?php _e( 'Largest Contentful Paint', 'performance-optimisation' ); ?></h4>
							<div class="wppo-vital-value" id="lcp-value">--</div>
							<div class="wppo-vital-status" id="lcp-status">--</div>
						</div>
						<div class="wppo-vital-card">
							<h4><?php _e( 'First Input Delay', 'performance-optimisation' ); ?></h4>
							<div class="wppo-vital-value" id="fid-value">--</div>
							<div class="wppo-vital-status" id="fid-status">--</div>
						</div>
						<div class="wppo-vital-card">
							<h4><?php _e( 'Cumulative Layout Shift', 'performance-optimisation' ); ?></h4>
							<div class="wppo-vital-value" id="cls-value">--</div>
							<div class="wppo-vital-status" id="cls-status">--</div>
						</div>
					</div>
				</div>

				<!-- Real-time Stats -->
				<div class="wppo-dashboard-section wppo-realtime">
					<h2><?php _e( 'Real-time Statistics', 'performance-optimisation' ); ?></h2>
					<div class="wppo-realtime-grid">
						<div class="wppo-stat-card">
							<h4><?php _e( 'Active Users', 'performance-optimisation' ); ?></h4>
							<div class="wppo-stat-value" id="active-users">--</div>
						</div>
						<div class="wppo-stat-card">
							<h4><?php _e( 'Requests/Min', 'performance-optimisation' ); ?></h4>
							<div class="wppo-stat-value" id="requests-per-minute">--</div>
						</div>
						<div class="wppo-stat-card">
							<h4><?php _e( 'Error Rate', 'performance-optimisation' ); ?></h4>
							<div class="wppo-stat-value" id="error-rate">--</div>
						</div>
						<div class="wppo-stat-card">
							<h4><?php _e( 'Memory Usage', 'performance-optimisation' ); ?></h4>
							<div class="wppo-stat-value" id="memory-usage">--</div>
						</div>
					</div>
				</div>

				<!-- Performance Charts -->
				<div class="wppo-dashboard-section wppo-charts">
					<h2><?php _e( 'Performance Trends', 'performance-optimisation' ); ?></h2>
					<div class="wppo-charts-container">
						<div class="wppo-chart-card">
							<h4><?php _e( 'Load Time Trend', 'performance-optimisation' ); ?></h4>
							<canvas id="load-time-chart"></canvas>
						</div>
						<div class="wppo-chart-card">
							<h4><?php _e( 'Cache Performance', 'performance-optimisation' ); ?></h4>
							<canvas id="cache-chart"></canvas>
						</div>
					</div>
				</div>

				<!-- Alerts and Bottlenecks -->
				<div class="wppo-dashboard-section wppo-alerts">
					<h2><?php _e( 'Alerts & Bottlenecks', 'performance-optimisation' ); ?></h2>
					<div class="wppo-alerts-container">
						<div class="wppo-alerts-list" id="alerts-list">
							<!-- Alerts will be populated by JavaScript -->
						</div>
						<div class="wppo-bottlenecks-list" id="bottlenecks-list">
							<!-- Bottlenecks will be populated by JavaScript -->
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render intelligent optimizer page
	 */
	public function renderOptimizerPage(): void {
		?>
		<div class="wrap wppo-optimizer-page">
			<h1><?php _e( 'Intelligent Optimizer', 'performance-optimisation' ); ?></h1>
			
			<div class="wppo-optimizer-intro">
				<p><?php _e( 'Our intelligent optimization system analyzes your site performance and provides personalized recommendations to improve speed, user experience, and SEO rankings.', 'performance-optimisation' ); ?></p>
			</div>

			<div id="wppo-optimizer-loading" class="wppo-loading">
				<div class="spinner is-active"></div>
				<p><?php _e( 'Analyzing your site performance...', 'performance-optimisation' ); ?></p>
			</div>

			<div id="wppo-optimizer-content" style="display: none;">
				<!-- Site Analysis Summary -->
				<div class="wppo-analysis-summary">
					<h2><?php _e( 'Site Analysis Summary', 'performance-optimisation' ); ?></h2>
					<div class="wppo-analysis-grid">
						<div class="wppo-analysis-card wppo-overall-score">
							<h3><?php _e( 'Overall Performance', 'performance-optimisation' ); ?></h3>
							<div class="wppo-score-display" id="overall-score">--</div>
							<div class="wppo-score-description" id="score-description">--</div>
						</div>
						<div class="wppo-analysis-card">
							<h3><?php _e( 'Optimization Potential', 'performance-optimisation' ); ?></h3>
							<div class="wppo-potential-display" id="optimization-potential">--</div>
						</div>
						<div class="wppo-analysis-card">
							<h3><?php _e( 'Priority Issues', 'performance-optimisation' ); ?></h3>
							<div class="wppo-issues-count" id="priority-issues">--</div>
						</div>
					</div>
				</div>

				<!-- Recommendations -->
				<div class="wppo-recommendations-section">
					<h2><?php _e( 'Intelligent Recommendations', 'performance-optimisation' ); ?></h2>
					<div class="wppo-recommendations-filter">
						<button class="wppo-filter-btn active" data-filter="all"><?php _e( 'All', 'performance-optimisation' ); ?></button>
						<button class="wppo-filter-btn" data-filter="critical"><?php _e( 'Critical', 'performance-optimisation' ); ?></button>
						<button class="wppo-filter-btn" data-filter="performance"><?php _e( 'Performance', 'performance-optimisation' ); ?></button>
						<button class="wppo-filter-btn" data-filter="caching"><?php _e( 'Caching', 'performance-optimisation' ); ?></button>
						<button class="wppo-filter-btn" data-filter="images"><?php _e( 'Images', 'performance-optimisation' ); ?></button>
					</div>
					<div id="recommendations-list" class="wppo-recommendations-list">
						<!-- Recommendations will be populated by JavaScript -->
					</div>
				</div>

				<!-- Quick Actions -->
				<div class="wppo-quick-actions">
					<h2><?php _e( 'Quick Optimization Actions', 'performance-optimisation' ); ?></h2>
					<div class="wppo-actions-grid">
						<button class="wppo-action-btn" id="optimize-cache">
							<span class="wppo-action-icon">🚀</span>
							<span class="wppo-action-text"><?php _e( 'Optimize Cache Settings', 'performance-optimisation' ); ?></span>
						</button>
						<button class="wppo-action-btn" id="optimize-images">
							<span class="wppo-action-icon">🖼️</span>
							<span class="wppo-action-text"><?php _e( 'Optimize Images', 'performance-optimisation' ); ?></span>
						</button>
						<button class="wppo-action-btn" id="optimize-database">
							<span class="wppo-action-icon">🗄️</span>
							<span class="wppo-action-text"><?php _e( 'Clean Database', 'performance-optimisation' ); ?></span>
						</button>
						<button class="wppo-action-btn" id="optimize-resources">
							<span class="wppo-action-icon">⚡</span>
							<span class="wppo-action-text"><?php _e( 'Optimize Resources', 'performance-optimisation' ); ?></span>
						</button>
					</div>
				</div>

				<!-- Estimated Improvements -->
				<div class="wppo-improvements-section">
					<h2><?php _e( 'Estimated Improvements', 'performance-optimisation' ); ?></h2>
					<div class="wppo-improvements-grid" id="improvements-grid">
						<!-- Improvements will be populated by JavaScript -->
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler for getting dashboard data
	 */
	public function ajaxGetDashboardData(): void {
		check_ajax_referer( 'wppo_monitor_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions', 'performance-optimisation' ) );
		}

		try {
			$dashboard_data = $this->monitor->getDashboardData();
			wp_send_json_success( $dashboard_data );
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to load dashboard data', 'performance-optimisation' ),
					'error'   => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * AJAX handler for applying optimizations
	 */
	public function ajaxApplyOptimization(): void {
		check_ajax_referer( 'wppo_monitor_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions', 'performance-optimisation' ) );
		}

		$optimization_type = sanitize_text_field( $_POST['optimization_type'] ?? '' );
		$optimization_data = json_decode( stripslashes( $_POST['optimization_data'] ?? '{}' ), true );

		if ( empty( $optimization_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid optimization type', 'performance-optimisation' ) ) );
		}

		try {
			$result = $this->optimizer->applyAutomaticOptimizations( array( $optimization_data ) );
			wp_send_json_success( $result );
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to apply optimization', 'performance-optimisation' ),
					'error'   => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * AJAX handler for tracking metrics from frontend
	 */
	public function ajaxTrackMetric(): void {
		// Verify nonce for logged-in users only
		if ( is_user_logged_in() ) {
			check_ajax_referer( 'wppo_monitor_nonce', 'nonce' );
		}

		$metric = sanitize_text_field( $_POST['metric'] ?? '' );
		$value  = floatval( $_POST['value'] ?? 0 );
		$url    = sanitize_text_field( $_POST['url'] ?? '' );

		if ( empty( $metric ) || $value <= 0 ) {
			wp_send_json_error( array( 'message' => 'Invalid metric data' ) );
		}

		try {
			// Track Core Web Vitals
			if ( in_array( $metric, array( 'lcp', 'fid', 'cls', 'fcp', 'ttfb' ) ) ) {
				$this->analytics->storeMetric(
					'core_vitals',
					array(
						$metric      => $value,
						'url'        => $url,
						'timestamp'  => current_time( 'mysql' ),
						'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
						'is_mobile'  => wp_is_mobile(),
					)
				);
			}

			wp_send_json_success( array( 'message' => 'Metric tracked successfully' ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => 'Failed to track metric' ) );
		}
	}
}
