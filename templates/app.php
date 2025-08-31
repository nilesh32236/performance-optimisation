<div class="wrap">
	<div id="performance-optimisation-admin-app">
		<div style="padding: 20px; text-align: center;">
			<h2><?php esc_html_e( 'Loading Performance Optimisation Settings...', 'performance-optimisation' ); ?></h2>
			<p><?php esc_html_e( 'Please wait while the application loads.', 'performance-optimisation' ); ?></p>
			<style>
				.wppo-loading-spinner {
					border: 4px solid #f3f3f3;
					border-top: 4px solid #0073aa;
					border-radius: 50%;
					width: 40px;
					height: 40px;
					animation: wppo-spin 1s linear infinite;
					margin: 20px auto;
				}
				@keyframes wppo-spin {
					0% { transform: rotate(0deg); }
					100% { transform: rotate(360deg); }
				}
			</style>
			<div class="wppo-loading-spinner"></div>
		</div>
	</div>
</div>