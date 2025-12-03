<?php
/**
 * Admin test page for drop-in management
 * Access via: /wp-admin/admin.php?page=test-dropin
 */

// Add admin menu
add_action(
	'admin_menu',
	function () {
		add_submenu_page(
			null, // No parent = hidden
			'Test Drop-in',
			'Test Drop-in',
			'manage_options',
			'test-dropin',
			'wppo_test_dropin_page'
		);
	}
);

function wppo_test_dropin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}

	echo '<div class="wrap">';
	echo '<h1>Advanced Cache Drop-in Test</h1>';

	$dropin_path   = WP_CONTENT_DIR . '/advanced-cache.php';
	$dropin_exists = file_exists( $dropin_path );

	echo '<h2>Current Status</h2>';
	echo '<p>Drop-in file exists: <strong>' . ( $dropin_exists ? 'YES' : 'NO' ) . '</strong></p>';

	if ( $dropin_exists ) {
		echo '<p>File size: ' . size_format( filesize( $dropin_path ) ) . '</p>';
		echo '<p>Last modified: ' . date( 'Y-m-d H:i:s', filemtime( $dropin_path ) ) . '</p>';
	}

	echo '<p>WP_CACHE constant: <strong>' . ( defined( 'WP_CACHE' ) && WP_CACHE ? 'ENABLED' : 'DISABLED' ) . '</strong></p>';

	// Handle actions
	if ( isset( $_GET['action'] ) && check_admin_referer( 'test-dropin-action' ) ) {
		try {
			$container = \PerformanceOptimisation\Core\ServiceContainer::getInstance();
			$service   = $container->get( 'PageCacheService' );

			switch ( $_GET['action'] ) {
				case 'create':
					$result = $service->create_advanced_cache_dropin();
					echo '<div class="notice notice-' . ( $result ? 'success' : 'error' ) . '"><p>';
					echo $result ? 'Drop-in created successfully!' : 'Failed to create drop-in.';
					echo '</p></div>';
					break;

				case 'remove':
					$result = $service->remove_advanced_cache_dropin();
					echo '<div class="notice notice-' . ( $result ? 'success' : 'error' ) . '"><p>';
					echo $result ? 'Drop-in removed successfully!' : 'Failed to remove drop-in.';
					echo '</p></div>';
					break;

				case 'enable':
					$result = $service->enable_cache();
					echo '<div class="notice notice-' . ( $result ? 'success' : 'error' ) . '"><p>';
					echo $result ? 'Cache enabled successfully!' : 'Failed to enable cache.';
					echo '</p></div>';
					break;

				case 'disable':
					$result = $service->disable_cache();
					echo '<div class="notice notice-' . ( $result ? 'success' : 'error' ) . '"><p>';
					echo $result ? 'Cache disabled successfully!' : 'Failed to disable cache.';
					echo '</p></div>';
					break;
			}

			// Refresh status
			$dropin_exists = file_exists( $dropin_path );

		} catch ( Exception $e ) {
			echo '<div class="notice notice-error"><p>Error: ' . esc_html( $e->getMessage() ) . '</p></div>';
		}
	}

	$nonce = wp_create_nonce( 'test-dropin-action' );

	echo '<h2>Actions</h2>';
	echo '<p>';
	echo '<a href="?page=test-dropin&action=create&_wpnonce=' . $nonce . '" class="button">Create Drop-in</a> ';
	echo '<a href="?page=test-dropin&action=remove&_wpnonce=' . $nonce . '" class="button">Remove Drop-in</a> ';
	echo '<a href="?page=test-dropin&action=enable&_wpnonce=' . $nonce . '" class="button button-primary">Enable Cache</a> ';
	echo '<a href="?page=test-dropin&action=disable&_wpnonce=' . $nonce . '" class="button">Disable Cache</a>';
	echo '</p>';

	if ( $dropin_exists ) {
		echo '<h2>Drop-in Content Preview</h2>';
		echo '<textarea readonly style="width:100%;height:300px;font-family:monospace;font-size:12px;">';
		echo esc_textarea( file_get_contents( $dropin_path ) );
		echo '</textarea>';
	}

	echo '</div>';
}

// Load this file
require_once __DIR__ . '/test-dropin-admin.php';
