<?php
/**
 * FINAL VERIFICATION - Complete System Check
 */

require_once __DIR__ . '/../../../wp-load.php';

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                  FINAL SYSTEM VERIFICATION                     ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$all_passed = true;
$critical_failed = false;

// ============================================================================
// 1. PHP EXTENSIONS
// ============================================================================
echo "1️⃣  PHP EXTENSIONS\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$extensions = [
	'GD Library' => extension_loaded('gd'),
	'JPEG' => function_exists('imagejpeg'),
	'PNG' => function_exists('imagepng'),
	'WebP' => function_exists('imagewebp'),
	'AVIF' => function_exists('imageavif'),
];

foreach ($extensions as $name => $status) {
	$icon = $status ? '✅' : '❌';
	echo sprintf("   %-20s %s\n", $name, $icon);
	if (!$status && $name !== 'AVIF') {
		$critical_failed = true;
	}
}
echo "\n";

// ============================================================================
// 2. SERVICES
// ============================================================================
echo "2️⃣  SERVICES REGISTRATION\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$container = \PerformanceOptimisation\Core\ServiceContainer::getInstance();
$services = [
	'PageCacheService' => 'page_cache_service',
	'BrowserCacheService' => 'browser_cache_service',
	'ImageService' => 'image_service',
	'ImageProcessor' => 'image_processor',
	'LazyLoadService' => 'lazy_load_service',
	'NextGenImageService' => 'next_gen_image_service',
];

foreach ($services as $name => $alias) {
	$registered = $container->has($alias);
	$icon = $registered ? '✅' : '❌';
	echo sprintf("   %-25s %s\n", $name, $icon);
	if (!$registered) {
		$critical_failed = true;
	}
}
echo "\n";

// ============================================================================
// 3. API ENDPOINTS
// ============================================================================
echo "3️⃣  API ENDPOINTS\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$endpoints = [
	'/cache/stats' => 'Cache Statistics',
	'/cache/clear' => 'Clear Cache',
	'/images/stats' => 'Image Statistics',
	'/settings' => 'Settings',
];

foreach ($endpoints as $endpoint => $description) {
	$url = home_url('/wp-json/performance-optimisation/v1' . $endpoint);
	$response = wp_remote_get($url);
	$code = wp_remote_retrieve_response_code($response);
	$working = in_array($code, [200, 401, 403]);
	$icon = $working ? '✅' : '❌';
	echo sprintf("   %-30s %s (HTTP %d)\n", $description, $icon, $code);
	if (!$working) {
		$all_passed = false;
	}
}
echo "\n";

// ============================================================================
// 4. DIRECTORIES & PERMISSIONS
// ============================================================================
echo "4️⃣  DIRECTORIES & PERMISSIONS\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$directories = [
	'Cache Directory' => WP_CONTENT_DIR . '/cache/wppo',
	'WPPO Directory' => WP_CONTENT_DIR . '/wppo',
];

foreach ($directories as $name => $path) {
	if (!file_exists($path)) {
		wp_mkdir_p($path);
	}
	$writable = is_writable($path);
	$icon = $writable ? '✅' : '❌';
	echo sprintf("   %-25s %s\n", $name, $icon);
	echo sprintf("   %-25s %s\n", '', $path);
	if (!$writable) {
		$critical_failed = true;
	}
}
echo "\n";

// ============================================================================
// 5. IMAGE OPTIMIZATION TEST
// ============================================================================
echo "5️⃣  IMAGE OPTIMIZATION TEST\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// Create test image
$test_dir = WP_CONTENT_DIR . '/wppo-test';
if (!file_exists($test_dir)) {
	wp_mkdir_p($test_dir);
}

$test_image = $test_dir . '/test.jpg';
$test_webp = $test_dir . '/test.webp';

// Create a simple test JPEG
$img = imagecreatetruecolor(100, 100);
$color = imagecolorallocate($img, 255, 0, 0);
imagefill($img, 0, 0, $color);
imagejpeg($img, $test_image, 90);
imagedestroy($img);

echo "   Creating test image... ✅\n";
echo "   Test image: $test_image\n";

// Test conversion
try {
	$image_processor = $container->get('image_processor');
	$result = $image_processor->convert($test_image, $test_webp, 'webp', 82);
	
	if ($result && file_exists($test_webp)) {
		$original_size = filesize($test_image);
		$webp_size = filesize($test_webp);
		$savings = round((($original_size - $webp_size) / $original_size) * 100, 2);
		
		echo "   Converting to WebP... ✅\n";
		echo sprintf("   Original: %s\n", size_format($original_size));
		echo sprintf("   WebP: %s\n", size_format($webp_size));
		echo sprintf("   Savings: %s%%\n", $savings);
		
		// Cleanup
		@unlink($test_image);
		@unlink($test_webp);
		@rmdir($test_dir);
	} else {
		echo "   Converting to WebP... ❌\n";
		$critical_failed = true;
	}
} catch (Exception $e) {
	echo "   Converting to WebP... ❌\n";
	echo "   Error: " . $e->getMessage() . "\n";
	$critical_failed = true;
}
echo "\n";

// ============================================================================
// 6. CACHE CLEAR TEST
// ============================================================================
echo "6️⃣  CACHE CLEAR TEST\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$cache_dir = WP_CONTENT_DIR . '/cache/wppo/pages';

// Create test cache
if (!file_exists($cache_dir)) {
	wp_mkdir_p($cache_dir . '/localhost/awm');
}
file_put_contents($cache_dir . '/localhost/awm/test.html', '<html>Test</html>');

echo "   Creating test cache... ✅\n";

// Clear cache
$page_cache = $container->get('page_cache_service');
$result = $page_cache->clear_all_cache();

if ($result && !file_exists($cache_dir . '/localhost/awm/test.html')) {
	echo "   Clearing cache... ✅\n";
} else {
	echo "   Clearing cache... ❌\n";
	$all_passed = false;
}
echo "\n";

// ============================================================================
// 7. SETTINGS
// ============================================================================
echo "7️⃣  PLUGIN SETTINGS\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$settings = get_option('wppo_settings', []);

if (empty($settings)) {
	echo "   ⚠️  No settings found (will be created on first save)\n";
} else {
	$cache_settings = $settings['cache_settings'] ?? [];
	$image_settings = $settings['image_optimization'] ?? [];
	
	echo "   Cache Settings:\n";
	echo sprintf("   - Page Cache: %s\n", ($cache_settings['page_cache_enabled'] ?? false) ? '✅ Enabled' : '❌ Disabled');
	echo sprintf("   - Browser Cache: %s\n", ($cache_settings['browser_cache_enabled'] ?? false) ? '✅ Enabled' : '❌ Disabled');
	
	echo "\n   Image Settings:\n";
	echo sprintf("   - Auto-convert: %s\n", ($image_settings['auto_convert_on_upload'] ?? false) ? '✅ Enabled' : '❌ Disabled');
	echo sprintf("   - WebP: %s\n", ($image_settings['webp_conversion'] ?? false) ? '✅ Enabled' : '❌ Disabled');
	echo sprintf("   - Lazy Loading: %s\n", ($image_settings['lazy_load_enabled'] ?? false) ? '✅ Enabled' : '❌ Disabled');
}
echo "\n";

// ============================================================================
// 8. MEDIA LIBRARY
// ============================================================================
echo "8️⃣  MEDIA LIBRARY\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$args = [
	'post_type' => 'attachment',
	'post_mime_type' => 'image',
	'post_status' => 'inherit',
	'posts_per_page' => -1,
	'fields' => 'ids',
];
$images = get_posts($args);
$total = count($images);
$optimized = 0;

foreach ($images as $id) {
	if (get_post_meta($id, '_wppo_optimized', true)) {
		$optimized++;
	}
}

echo sprintf("   Total Images: %d\n", $total);
echo sprintf("   Optimized: %d\n", $optimized);
echo sprintf("   Pending: %d\n", $total - $optimized);

if ($total == 0) {
	echo "\n   ℹ️  No images in library - upload images to test\n";
}
echo "\n";

// ============================================================================
// FINAL SUMMARY
// ============================================================================
echo "╔════════════════════════════════════════════════════════════════╗\n";
if ($critical_failed) {
	echo "║  ❌ CRITICAL ISSUES FOUND - System not fully operational      ║\n";
} elseif (!$all_passed) {
	echo "║  ⚠️  MINOR ISSUES - System mostly operational                 ║\n";
} else {
	echo "║  ✅ ALL TESTS PASSED - System fully operational!              ║\n";
}
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// ============================================================================
// NEXT STEPS
// ============================================================================
echo "📋 NEXT STEPS:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

if ($critical_failed) {
	echo "❌ CRITICAL: Fix the failed items above before proceeding\n\n";
} else {
	echo "1. Access Admin Interface:\n";
	echo "   → WordPress Admin → Performance Optimisation\n\n";
	
	echo "2. Configure Settings:\n";
	echo "   → Go to 'Caching' tab - Enable page cache\n";
	echo "   → Go to 'Images' tab - Configure image optimization\n";
	echo "   → Click 'Save Settings' on each tab\n\n";
	
	echo "3. Test Image Optimization:\n";
	echo "   → Upload a JPEG or PNG image\n";
	echo "   → Check /wp-content/wppo/uploads/ for WebP version\n";
	echo "   → View image on frontend - should serve WebP\n\n";
	
	echo "4. Test Cache:\n";
	echo "   → Visit your homepage\n";
	echo "   → Check /wp-content/cache/wppo/pages/ for cached files\n";
	echo "   → Click 'Clear Page Cache' button\n";
	echo "   → Verify cache is cleared\n\n";
	
	echo "5. Monitor Performance:\n";
	echo "   → Check 'Dashboard' tab for statistics\n";
	echo "   → Monitor space savings\n";
	echo "   → Review optimization metrics\n\n";
}

echo "📚 DOCUMENTATION:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "- IMAGE_OPTIMIZATION_IMPLEMENTATION.md - Technical details\n";
echo "- ADMIN_SETUP_GUIDE.md - User guide\n";
echo "- VERIFICATION_COMPLETE.md - System verification\n";
echo "- CACHE_CLEAR_VERIFIED.md - Cache functionality\n\n";

echo "🎉 SYSTEM STATUS: ";
if ($critical_failed) {
	echo "❌ NOT READY\n";
	exit(1);
} elseif (!$all_passed) {
	echo "⚠️  MOSTLY READY\n";
	exit(0);
} else {
	echo "✅ READY TO USE!\n";
	exit(0);
}
