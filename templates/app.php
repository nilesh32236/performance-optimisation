<?php
// Security check
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
}

// Verify nonce for admin page access
$nonce = wp_create_nonce( 'wppo_admin_page' );

// Enqueue loading spinner CSS
wp_enqueue_style( 
	'wppo-admin-loading', 
	plugin_dir_url( __DIR__ ) . 'assets/css/admin-loading.css', 
	array(), 
	'1.0.0' 
);
?>
<div class="wrap">
	<div id="performance-optimisation-admin-app" data-nonce="<?php echo esc_attr( $nonce ); ?>">
		<div style="padding: 20px; text-align: center;">
			<h2><?php esc_html_e( 'Loading Performance Optimisation Settings...', 'performance-optimisation' ); ?></h2>
			<p><?php esc_html_e( 'Please wait while the application loads.', 'performance-optimisation' ); ?></p>
			<div class="wppo-loading-spinner"></div>
		</div>
	</div>
</div>