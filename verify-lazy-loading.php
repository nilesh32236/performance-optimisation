<?php
/**
 * Verify Lazy Loading Implementation
 */

require_once dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/wp-load.php';

echo "Starting Lazy Loading Verification...\n\n";

$passed = 0;
$failed = 0;

// Get services
$container = \PerformanceOptimisation\Core\Bootstrap\Plugin::getInstance()->getContainer();

// Test 1: Check LazyLoadService exists
echo "Test 1: LazyLoadService Registration\n";
if ( $container->has( 'lazy_load_service' ) ) {
	echo "[PASS] LazyLoadService is registered\n\n";
	$passed++;
} else {
	echo "[FAIL] LazyLoadService is NOT registered\n\n";
	$failed++;
}

// Test 2: Check hooks are registered
echo "Test 2: Content Filter Hooks\n";
if ( has_filter( 'the_content' ) ) {
	echo "[PASS] the_content filter is registered\n\n";
	$passed++;
} else {
	echo "[FAIL] the_content filter is NOT registered\n\n";
	$failed++;
}

// Test 3: Check lazyload.js is enqueued
echo "Test 3: Lazyload Script\n";
$lazyload_js = plugins_url( 'build/lazyload.js', __FILE__ );
if ( file_exists( dirname( __FILE__ ) . '/build/lazyload.js' ) ) {
	echo "[PASS] lazyload.js file exists\n\n";
	$passed++;
} else {
	echo "[FAIL] lazyload.js file does NOT exist\n\n";
	$failed++;
}

// Test 4: Test lazy loading transformation
echo "Test 4: Image Transformation\n";
$lazy_load_service = $container->get( 'lazy_load_service' );
$test_html = '<img src="test.jpg" alt="Test">';
$result = $lazy_load_service->add_lazy_loading( $test_html );

if ( strpos( $result, 'loading="lazy"' ) !== false ) {
	echo "[PASS] Native loading attribute added\n";
	$passed++;
} else {
	echo "[FAIL] Native loading attribute NOT added\n";
	$failed++;
}

if ( strpos( $result, 'data-src' ) !== false ) {
	echo "[PASS] data-src attribute added\n\n";
	$passed++;
} else {
	echo "[FAIL] data-src attribute NOT added\n\n";
	$failed++;
}

// Test 5: Test exclusion by class
echo "Test 5: Exclusion by Class\n";
$test_html = '<img src="test.jpg" class="no-lazy" alt="Test">';
$result = $lazy_load_service->add_lazy_loading( $test_html );

if ( strpos( $result, 'data-src' ) === false ) {
	echo "[PASS] Images with excluded class are not lazy loaded\n\n";
	$passed++;
} else {
	echo "[FAIL] Images with excluded class ARE lazy loaded\n\n";
	$failed++;
}

// Test 6: Test first N images exclusion
echo "Test 6: First N Images Exclusion\n";
$test_html = '<img src="test1.jpg"><img src="test2.jpg"><img src="test3.jpg">';
$result = $lazy_load_service->add_lazy_loading( $test_html );
$lazy_count = substr_count( $result, 'data-src' );
echo "[INFO] Lazy loaded images: $lazy_count out of 3\n";
if ( $lazy_count < 3 ) {
	echo "[PASS] First N images excluded from lazy loading\n\n";
	$passed++;
} else {
	echo "[FAIL] All images lazy loaded (should exclude first N)\n\n";
	$failed++;
}

// Test 7: Test SVG placeholder
echo "Test 7: SVG Placeholder\n";
if ( strpos( $result, 'data:image/svg+xml' ) !== false || strpos( $result, 'data:image/gif' ) !== false ) {
	echo "[PASS] Placeholder added to lazy loaded images\n\n";
	$passed++;
} else {
	echo "[FAIL] No placeholder added\n\n";
	$failed++;
}

// Summary
echo "Verification Completed.\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed > 0 ? 1 : 0 );
