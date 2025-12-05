<?php
/**
 * Test Cache Clear Functionality
 */

// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';

echo "=== Cache Clear Test ===\n\n";

// Check if user is admin
if ( ! current_user_can( 'manage_options' ) ) {
	echo "❌ Error: You must be logged in as admin\n";
	echo "Run this from WordPress admin or set up authentication\n";
	exit( 1 );
}

// Get cache directory
$cache_dir = WP_CONTENT_DIR . '/cache/wppo/pages';

echo "1. Checking cache directory:\n";
echo "   Path: $cache_dir\n";

if ( ! file_exists( $cache_dir ) ) {
	echo "   ℹ️  Cache directory doesn't exist (no cache files)\n\n";
} else {
	// Count files before
	$files_before = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $cache_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	$count_before = iterator_count( $files_before );

	echo "   Files before clear: $count_before\n\n";

	// Get PageCacheService
	echo "2. Getting PageCacheService:\n";
	$container = \PerformanceOptimisation\Core\ServiceContainer::getInstance();

	if ( ! $container->has( 'page_cache_service' ) ) {
		echo "   ❌ PageCacheService not registered\n";
		exit( 1 );
	}

	$page_cache = $container->get( 'page_cache_service' );
	echo "   ✅ PageCacheService loaded\n\n";

	// Clear cache
	echo "3. Clearing cache:\n";
	$result = $page_cache->clear_all_cache();

	if ( $result ) {
		echo "   ✅ Cache cleared successfully\n\n";
	} else {
		echo "   ❌ Failed to clear cache\n\n";
		exit( 1 );
	}

	// Count files after
	echo "4. Verifying cache cleared:\n";
	if ( ! file_exists( $cache_dir ) ) {
		echo "   ✅ Cache directory removed\n";
		echo "   Files after clear: 0\n";
	} else {
		$files_after = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $cache_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		$count_after = iterator_count( $files_after );

		echo "   Files after clear: $count_after\n";

		if ( $count_after == 0 ) {
			echo "   ✅ All cache files removed\n";
		} else {
			echo "   ⚠️  Some files still remain\n";
		}
	}
}

echo "\n=== Test Complete ===\n";
