<?php
/**
 * Comprehensive Image Optimization Verification
 */

// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║     Image Optimization System Verification                   ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$all_passed = true;

// Test 1: PHP Extensions
echo "1️⃣  PHP Image Extensions\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$extensions = [
	'GD Library' => extension_loaded('gd'),
	'JPEG Support' => function_exists('imagejpeg'),
	'PNG Support' => function_exists('imagepng'),
	'WebP Support' => function_exists('imagewebp'),
	'AVIF Support' => function_exists('imageavif'),
];

foreach ($extensions as $name => $status) {
	echo sprintf("   %-20s %s\n", $name . ':', $status ? '✅ Yes' : '❌ No');
	if (!$status && $name !== 'AVIF Support') $all_passed = false;
}
echo "\n";

// Test 2: Service Registration
echo "2️⃣  Service Registration\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$container = \PerformanceOptimisation\Core\ServiceContainer::getInstance();
$services = [
	'ImageService' => 'image_service',
	'ImageProcessor' => 'image_processor',
	'LazyLoadService' => 'lazy_load_service',
	'NextGenImageService' => 'next_gen_image_service',
];

foreach ($services as $name => $alias) {
	$registered = $container->has($alias);
	echo sprintf("   %-25s %s\n", $name . ':', $registered ? '✅ Registered' : '❌ Not Registered');
	if (!$registered) $all_passed = false;
}
echo "\n";

// Test 3: API Endpoints
echo "3️⃣  API Endpoints\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$endpoints = [
	'/images/stats',
	'/images/optimize',
	'/images/batch-optimize',
	'/images/convert',
];

foreach ($endpoints as $endpoint) {
	$url = home_url('/wp-json/performance-optimisation/v1' . $endpoint);
	$response = wp_remote_get($url);
	$code = wp_remote_retrieve_response_code($response);
	// 401 = authenticated endpoint (good), 404 = not found (bad)
	$status = in_array($code, [200, 401, 403]);
	echo sprintf("   %-30s %s (HTTP %d)\n", $endpoint, $status ? '✅ Available' : '❌ Not Found', $code);
	if (!$status) $all_passed = false;
}
echo "\n";

// Test 4: Directory Permissions
echo "4️⃣  Directory Permissions\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$wppo_dir = WP_CONTENT_DIR . '/wppo';
if (!file_exists($wppo_dir)) {
	wp_mkdir_p($wppo_dir);
}
$writable = is_writable($wppo_dir);
echo sprintf("   %-25s %s\n", 'WPPO Directory:', $writable ? '✅ Writable' : '❌ Not Writable');
echo sprintf("   %-25s %s\n", 'Path:', $wppo_dir);
if (!$writable) $all_passed = false;
echo "\n";

// Test 5: Image Statistics
echo "5️⃣  Image Statistics\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
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

echo sprintf("   %-25s %d\n", 'Total Images:', $total);
echo sprintf("   %-25s %d\n", 'Optimized:', $optimized);
echo sprintf("   %-25s %d\n", 'Pending:', $total - $optimized);
echo "\n";

// Test 6: Settings
echo "6️⃣  Plugin Settings\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$settings = get_option('wppo_settings', []);
$image_settings = $settings['image_optimization'] ?? [];

if (empty($image_settings)) {
	echo "   ⚠️  No image optimization settings found\n";
	echo "   💡 Settings will be created when you save from admin\n";
} else {
	echo sprintf("   %-30s %s\n", 'Auto-convert on upload:', ($image_settings['auto_convert_on_upload'] ?? false) ? '✅ Enabled' : '❌ Disabled');
	echo sprintf("   %-30s %s\n", 'WebP conversion:', ($image_settings['webp_conversion'] ?? false) ? '✅ Enabled' : '❌ Disabled');
	echo sprintf("   %-30s %s\n", 'AVIF conversion:', ($image_settings['avif_conversion'] ?? false) ? '✅ Enabled' : '❌ Disabled');
	echo sprintf("   %-30s %s\n", 'Lazy loading:', ($image_settings['lazy_load_enabled'] ?? false) ? '✅ Enabled' : '❌ Disabled');
	echo sprintf("   %-30s %d%%\n", 'Quality:', $image_settings['quality'] ?? 82);
}
echo "\n";

// Test 7: Test Image Conversion
echo "7️⃣  Image Conversion Test\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
if ($total > 0) {
	$test_image_id = $images[0];
	$test_image_path = get_attached_file($test_image_id);
	
	if (file_exists($test_image_path)) {
		echo sprintf("   Test Image: %s\n", basename($test_image_path));
		echo sprintf("   Path: %s\n", $test_image_path);
		
		// Try to convert
		try {
			$image_processor = $container->get('image_processor');
			$webp_path = str_replace(
				WP_CONTENT_DIR,
				WP_CONTENT_DIR . '/wppo',
				preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $test_image_path)
			);
			
			$webp_dir = dirname($webp_path);
			if (!file_exists($webp_dir)) {
				wp_mkdir_p($webp_dir);
			}
			
			echo "   Converting to WebP...\n";
			$result = $image_processor->convert($test_image_path, $webp_path, 'webp', 82);
			
			if ($result && file_exists($webp_path)) {
				$original_size = filesize($test_image_path);
				$webp_size = filesize($webp_path);
				$savings = round((($original_size - $webp_size) / $original_size) * 100, 2);
				
				echo "   ✅ Conversion Successful!\n";
				echo sprintf("   Original: %s\n", size_format($original_size));
				echo sprintf("   WebP: %s\n", size_format($webp_size));
				echo sprintf("   Savings: %s%%\n", $savings);
			} else {
				echo "   ❌ Conversion Failed\n";
				$all_passed = false;
			}
		} catch (Exception $e) {
			echo "   ❌ Error: " . $e->getMessage() . "\n";
			$all_passed = false;
		}
	}
} else {
	echo "   ℹ️  No images in media library to test\n";
	echo "   💡 Upload an image to test conversion\n";
}
echo "\n";

// Final Summary
echo "╔══════════════════════════════════════════════════════════════╗\n";
if ($all_passed) {
	echo "║  ✅ ALL TESTS PASSED - System is fully operational!         ║\n";
} else {
	echo "║  ⚠️  SOME TESTS FAILED - Check errors above                 ║\n";
}
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

echo "📋 Next Steps:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "1. Access admin: WordPress Admin → Performance Optimisation → Images\n";
echo "2. Configure settings and click 'Save Settings'\n";
echo "3. Upload a test image to verify automatic conversion\n";
echo "4. Check /wp-content/wppo/ for converted images\n";
echo "5. Use 'Optimize All Images' button for existing images\n\n";

echo "📚 Documentation:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "- IMAGE_OPTIMIZATION_IMPLEMENTATION.md - Technical details\n";
echo "- ADMIN_SETUP_GUIDE.md - User guide\n";
echo "- API_INTEGRATION.md - API documentation\n";
echo "- ENDPOINT_FIX.md - Troubleshooting\n\n";
