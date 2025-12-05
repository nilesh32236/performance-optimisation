<?php
/**
 * Verify Cache Operations
 * Tests cache generation, retrieval, and clearing
 */

// Load WordPress
require_once dirname( dirname( dirname( __DIR__ ) ) ) . '/wp-load.php';

echo "Starting Cache Operations Verification...\n\n";

$passed = 0;
$failed = 0;

// Get PageCacheService
$container  = \PerformanceOptimisation\Core\Bootstrap\Plugin::getInstance()->getContainer();
$page_cache = $container->get( 'page_cache_service' );
$cache_dir  = WP_CONTENT_DIR . '/cache/wppo/pages';

// Test 1: Clear all cache
echo "Test 1: Clear All Cache\n";
$result = $page_cache->clear_all_cache();
if ( $result ) {
	echo "[PASS] Cache cleared successfully\n\n";
	++$passed;
} else {
	echo "[FAIL] Failed to clear cache\n\n";
	++$failed;
}

// Test 2: Get initial stats
echo "Test 2: Get Cache Stats (Empty)\n";
$stats = $page_cache->get_cache_stats();
if ( $stats['files'] === 0 ) {
	echo "[PASS] Stats show 0 files after clear\n";
	echo "[INFO] Size: {$stats['size_formatted']}\n\n";
	++$passed;
} else {
	echo "[FAIL] Stats show {$stats['files']} files (expected 0)\n\n";
	++$failed;
}

// Test 3: Generate cache for home page
echo "Test 3: Generate Cache for Home Page\n";
$home_url = home_url( '/' );
$response = wp_remote_get( $home_url, array( 'timeout' => 30 ) );
sleep( 1 ); // Allow cache to be written

$stats = $page_cache->get_cache_stats();
if ( $stats['files'] > 0 ) {
	echo "[PASS] Cache file generated\n";
	echo "[INFO] Files: {$stats['files']}, Size: {$stats['size_formatted']}\n\n";
	++$passed;
} else {
	echo "[FAIL] No cache file generated\n\n";
	++$failed;
}

// Test 4: Verify cache file exists
echo "Test 4: Verify Cache File Exists\n";
$domain     = parse_url( home_url(), PHP_URL_HOST );
$domain_dir = $cache_dir . '/' . $domain;
$files      = glob( $domain_dir . '/**/*.html' );
if ( ! empty( $files ) ) {
	echo "[PASS] Cache file exists\n";
	echo '[INFO] File: ' . basename( dirname( $files[0] ) ) . '/' . basename( $files[0] ) . "\n";
	echo '[INFO] File size: ' . size_format( filesize( $files[0] ) ) . "\n\n";
	$cache_file = $files[0];
	++$passed;
} else {
	echo "[FAIL] Cache file not found\n\n";
	++$failed;
}

// Test 5: Warm cache for multiple URLs
echo "Test 5: Warm Cache for Multiple URLs\n";
$urls   = array(
	home_url( '/' ),
	home_url( '/sample-page/' ),
);
$warmed = $page_cache->warm_cache( $urls );
sleep( 2 ); // Allow cache to be written

if ( $warmed > 0 ) {
	echo "[PASS] Warmed $warmed URLs\n\n";
	++$passed;
} else {
	echo "[FAIL] Failed to warm cache\n\n";
	++$failed;
}

// Test 6: Get updated stats
echo "Test 6: Get Updated Cache Stats\n";
$stats = $page_cache->get_cache_stats();
echo "[INFO] Files: {$stats['files']}\n";
echo "[INFO] Size: {$stats['size_formatted']}\n";
echo "[INFO] Hit Rate: {$stats['hit_rate']}%\n";
if ( $stats['files'] > 0 ) {
	echo "[PASS] Cache stats updated\n\n";
	++$passed;
} else {
	echo "[FAIL] No cache files found\n\n";
	++$failed;
}

// Test 7: Clear specific URL cache
echo "Test 7: Clear Specific URL Cache\n";
$result = $page_cache->clear_url_cache( home_url( '/' ) );
if ( $result ) {
	echo "[PASS] URL cache cleared\n\n";
	++$passed;
} else {
	echo "[FAIL] Failed to clear URL cache\n\n";
	++$failed;
}

// Test 8: Verify cache file removed
echo "Test 8: Verify Cache File Removed\n";
if ( ! file_exists( $cache_file ) ) {
	echo "[PASS] Cache file removed\n\n";
	++$passed;
} else {
	echo "[FAIL] Cache file still exists\n\n";
	++$failed;
}

// Test 9: Test cache with POST request (should not cache)
echo "Test 9: POST Request Should Not Cache\n";
$_SERVER['REQUEST_METHOD'] = 'POST';
$should_cache              = $page_cache->should_cache_page();
if ( ! $should_cache ) {
	echo "[PASS] POST requests not cached\n\n";
	++$passed;
} else {
	echo "[FAIL] POST requests being cached\n\n";
	++$failed;
}
$_SERVER['REQUEST_METHOD'] = 'GET';

// Test 10: Final cleanup
echo "Test 10: Final Cleanup\n";
$result = $page_cache->clear_all_cache();
if ( $result ) {
	echo "[PASS] Final cleanup successful\n\n";
	++$passed;
} else {
	echo "[FAIL] Final cleanup failed\n\n";
	++$failed;
}

// Summary
echo "Verification Completed.\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed > 0 ? 1 : 0 );
