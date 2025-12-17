<?php
/**
 * Monitoring System Activation Script
 *
 * This script can be run to activate the intelligent monitoring system
 *
 * @package PerformanceOptimisation
 * @since   2.1.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activate the intelligent monitoring system
 */
function wppo_activate_monitoring_system() {
	// Include the monitoring integration
	$integration_file = __DIR__ . '/includes/monitoring-integration.php';

	if ( file_exists( $integration_file ) ) {
		require_once $integration_file;

		// Initialize monitoring
		wppo_initialize_monitoring_system();

		// Show success message
		add_action(
			'admin_notices',
			function () {
				?>
			<div class="notice notice-success is-dismissible">
				<p>
					<strong><?php _e( 'Performance Monitoring Activated!', 'performance-optimisation' ); ?></strong>
					<?php _e( 'The intelligent monitoring system is now active and collecting performance data.', 'performance-optimisation' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wppo-monitor' ) ); ?>" class="button button-small" style="margin-left: 10px;">
						<?php _e( 'View Dashboard', 'performance-optimisation' ); ?>
					</a>
				</p>
			</div>
				<?php
			}
		);

		return true;
	}

	return false;
}

// Auto-activate monitoring if not already active
if ( ! get_option( 'wppo_monitoring_activated', false ) ) {
	add_action(
		'admin_init',
		function () {
			if ( wppo_activate_monitoring_system() ) {
				update_option( 'wppo_monitoring_activated', true );
			}
		}
	);
}

/**
 * Add monitoring activation to plugin actions
 */
function wppo_add_monitoring_activation_link( $links ) {
	if ( ! get_option( 'wppo_monitoring_activated', false ) ) {
		$activation_link = sprintf(
			'<a href="%s" style="color: #46b450; font-weight: 600;">%s</a>',
			esc_url( add_query_arg( 'wppo_activate_monitoring', '1', admin_url( 'plugins.php' ) ) ),
			__( 'Activate Monitoring', 'performance-optimisation' )
		);
		array_unshift( $links, $activation_link );
	}

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __DIR__ . '/performance-optimisation.php' ), 'wppo_add_monitoring_activation_link' );

/**
 * Handle monitoring activation request
 */
function wppo_handle_monitoring_activation_request() {
	if ( isset( $_GET['wppo_activate_monitoring'] ) && current_user_can( 'manage_options' ) ) {
		if ( wppo_activate_monitoring_system() ) {
			update_option( 'wppo_monitoring_activated', true );

			wp_redirect(
				add_query_arg(
					'wppo_monitoring_activated',
					'1',
					admin_url( 'admin.php?page=wppo-monitor' )
				)
			);
			exit;
		}
	}
}
add_action( 'admin_init', 'wppo_handle_monitoring_activation_request' );

/**
 * Show monitoring activation success message
 */
function wppo_show_monitoring_activation_success() {
	if ( isset( $_GET['wppo_monitoring_activated'] ) ) {
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<strong><?php _e( 'Monitoring System Activated!', 'performance-optimisation' ); ?></strong>
				<?php _e( 'Welcome to the intelligent performance monitoring dashboard.', 'performance-optimisation' ); ?>
			</p>
		</div>
		<?php
	}
}
add_action( 'admin_notices', 'wppo_show_monitoring_activation_success' );

/**
 * Add monitoring system info to plugin description
 */
function wppo_add_monitoring_plugin_meta( $plugin_meta, $plugin_file ) {
	if ( plugin_basename( __DIR__ . '/performance-optimisation.php' ) === $plugin_file ) {
		$monitoring_status = get_option( 'wppo_monitoring_activated', false );

		if ( $monitoring_status ) {
			$plugin_meta[] = sprintf(
				'<span style="color: #46b450;">%s</span> | <a href="%s">%s</a>',
				__( 'Monitoring Active', 'performance-optimisation' ),
				esc_url( admin_url( 'admin.php?page=wppo-monitor' ) ),
				__( 'View Dashboard', 'performance-optimisation' )
			);
		} else {
			$plugin_meta[] = sprintf(
				'<span style="color: #dc3232;">%s</span> | <a href="%s">%s</a>',
				__( 'Monitoring Inactive', 'performance-optimisation' ),
				esc_url( add_query_arg( 'wppo_activate_monitoring', '1', admin_url( 'plugins.php' ) ) ),
				__( 'Activate Now', 'performance-optimisation' )
			);
		}
	}

	return $plugin_meta;
}
add_filter( 'plugin_row_meta', 'wppo_add_monitoring_plugin_meta', 10, 2 );

// Include monitoring integration if activated
if ( get_option( 'wppo_monitoring_activated', false ) ) {
	$integration_file = __DIR__ . '/includes/monitoring-integration.php';
	if ( file_exists( $integration_file ) ) {
		require_once $integration_file;
	}
}
