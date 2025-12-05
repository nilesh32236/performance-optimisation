<?php
/**
 * Test script for advanced-cache.php drop-in creation
 */

require_once __DIR__ . '/../../../wp-load.php';

echo "Testing advanced-cache.php drop-in creation...\n\n";

// Get the service container
$container          = \PerformanceOptimisation\Core\ServiceContainer::get_instance();
$page_cache_service = $container->get( 'PageCacheService' );

echo "1. Testing drop-in creation...\n";
$result = $page_cache_service->create_advanced_cache_dropin();
echo '   Result: ' . ( $result ? 'SUCCESS' : 'FAILED' ) . "\n";

$dropin_path = WP_CONTENT_DIR . '/advanced-cache.php';
echo '   File exists: ' . ( file_exists( $dropin_path ) ? 'YES' : 'NO' ) . "\n";

if ( file_exists( $dropin_path ) ) {
	$size = filesize( $dropin_path );
	echo "   File size: {$size} bytes\n";

	// Check if WP_CACHE constant is defined
	echo '   WP_CACHE defined: ' . ( defined( 'WP_CACHE' ) ? ( WP_CACHE ? 'YES (true)' : 'YES (false)' ) : 'NO' ) . "\n";
}

echo "\n2. Testing drop-in removal...\n";
$result = $page_cache_service->remove_advanced_cache_dropin();
echo '   Result: ' . ( $result ? 'SUCCESS' : 'FAILED' ) . "\n";
echo '   File exists: ' . ( file_exists( $dropin_path ) ? 'YES' : 'NO' ) . "\n";

echo "\n3. Testing enable_cache() method...\n";
$result = $page_cache_service->enable_cache();
echo '   Result: ' . ( $result ? 'SUCCESS' : 'FAILED' ) . "\n";
echo '   File exists: ' . ( file_exists( $dropin_path ) ? 'YES' : 'NO' ) . "\n";

echo "\nTest complete!\n";
