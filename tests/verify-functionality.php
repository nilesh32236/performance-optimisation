<?php
/**
 * Quick Functionality Verification Script
 * 
 * Run this script to quickly verify core functionality works
 * Usage: wp eval-file verify-functionality.php
 */

if (!defined('ABSPATH')) {
    die('Direct access not allowed');
}

echo "=== Performance Optimisation Plugin - Functionality Verification ===\n\n";

// Test 1: Check if plugin is active
echo "1. Plugin Status: ";
if (is_plugin_active('performance-optimisation/performance-optimisation.php')) {
    echo "✅ ACTIVE\n";
} else {
    echo "❌ INACTIVE\n";
    exit;
}

// Test 2: Check service container
echo "2. Service Container: ";
try {
    $container = \PerformanceOptimisation\Core\ServiceContainer::getInstance();
    echo "✅ WORKING\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// Test 3: Check utility classes
echo "3. Utility Classes:\n";

// FileSystemUtil
echo "   - FileSystemUtil: ";
try {
    $test_path = '/tmp/test.txt';
    \PerformanceOptimisation\Utils\FileSystemUtil::writeFile($test_path, 'test');
    $content = \PerformanceOptimisation\Utils\FileSystemUtil::readFile($test_path);
    \PerformanceOptimisation\Utils\FileSystemUtil::deleteFile($test_path);
    echo ($content === 'test') ? "✅ WORKING\n" : "❌ FAILED\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// CacheUtil
echo "   - CacheUtil: ";
try {
    $cache_key = \PerformanceOptimisation\Utils\CacheUtil::generateCacheKey('test_data', 'test');
    $cache_enabled = \PerformanceOptimisation\Utils\CacheUtil::isCacheEnabled('page');
    echo (strlen($cache_key) > 0) ? "✅ WORKING\n" : "❌ FAILED\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// ValidationUtil
echo "   - ValidationUtil: ";
try {
    $valid_url = \PerformanceOptimisation\Utils\ValidationUtil::isValidUrl('https://example.com');
    $valid_email = \PerformanceOptimisation\Utils\ValidationUtil::validateEmail('test@example.com');
    echo ($valid_url && $valid_email) ? "✅ WORKING\n" : "❌ FAILED\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// Test 4: Check services
echo "4. Services:\n";

// Cache Service
echo "   - CacheService: ";
try {
    $cache_service = $container->get('cache_service');
    $enabled = $cache_service->isCacheEnabled();
    echo "✅ LOADED (Cache " . ($enabled ? "enabled" : "disabled") . ")\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// Test 5: Check optimizers
echo "5. Optimizers:\n";

// CSS Optimizer
echo "   - ModernCssOptimizer: ";
try {
    $css_optimizer = new \PerformanceOptimisation\Optimizers\ModernCssOptimizer($container);
    $test_css = "body { color: red; }";
    $result = $css_optimizer->optimize($test_css);
    echo (isset($result['content'])) ? "✅ WORKING\n" : "❌ FAILED\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// JS Optimizer
echo "   - JsOptimizer: ";
try {
    $js_optimizer = new \PerformanceOptimisation\Optimizers\JsOptimizer($container);
    $test_js = "console.log('test');";
    $result = $js_optimizer->optimize($test_js);
    echo (isset($result['content'])) ? "✅ WORKING\n" : "❌ FAILED\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// Image Processor
echo "   - ModernImageProcessor: ";
try {
    $image_processor = new \PerformanceOptimisation\Optimizers\ModernImageProcessor($container);
    $supported = $image_processor->get_supported_types();
    echo (in_array('jpg', $supported)) ? "✅ WORKING\n" : "❌ FAILED\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// Test 6: Check database tables (if any)
echo "6. Database: ";
global $wpdb;
try {
    // Check if any custom tables exist
    $tables = $wpdb->get_results("SHOW TABLES LIKE '{$wpdb->prefix}wppo_%'");
    echo "✅ ACCESSIBLE (" . count($tables) . " custom tables)\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// Test 7: Check file permissions
echo "7. File Permissions:\n";
$upload_dir = wp_upload_dir();
$cache_dir = $upload_dir['basedir'] . '/wppo-cache';

echo "   - Upload directory: ";
echo (is_writable($upload_dir['basedir'])) ? "✅ WRITABLE\n" : "❌ NOT WRITABLE\n";

echo "   - Cache directory: ";
if (!is_dir($cache_dir)) {
    wp_mkdir_p($cache_dir);
}
echo (is_writable($cache_dir)) ? "✅ WRITABLE\n" : "❌ NOT WRITABLE\n";

// Test 8: Check REST API endpoints
echo "8. REST API Endpoints:\n";
$routes = rest_get_server()->get_routes();

echo "   - Cache endpoint: ";
echo (isset($routes['/performance-optimisation/v1/cache/clear'])) ? "✅ REGISTERED\n" : "❌ NOT FOUND\n";

echo "   - Images endpoint: ";
echo (isset($routes['/performance-optimisation/v1/images/optimize'])) ? "✅ REGISTERED\n" : "❌ NOT FOUND\n";

// Test 9: Check admin capabilities
echo "9. Admin Integration:\n";

echo "   - Admin menu: ";
global $menu;
$found_menu = false;
foreach ($menu as $item) {
    if (isset($item[2]) && strpos($item[2], 'performance-optimisation') !== false) {
        $found_menu = true;
        break;
    }
}
echo $found_menu ? "✅ ADDED\n" : "❌ NOT FOUND\n";

// Test 10: Memory usage check
echo "10. Performance:\n";
$memory_usage = memory_get_usage(true);
$memory_mb = round($memory_usage / 1024 / 1024, 2);
echo "    - Memory usage: {$memory_mb}MB ";
echo ($memory_mb < 50) ? "✅ GOOD\n" : "⚠️ HIGH\n";

echo "\n=== Verification Complete ===\n";
echo "If any tests show ❌, check the error messages and fix the issues.\n";
echo "For detailed testing, follow the manual test cases in MANUAL_TEST_CASES.md\n";
