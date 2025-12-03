<?php
/**
 * Verification script for Cache Exclusion Functionality
 * 
 * Tests the matching logic in PageCacheService.
 */

namespace PerformanceOptimisation\Services {
    class SettingsService {
        private $settings = [];
        public function get_setting($group, $key, $default = null) {
            return $this->settings[$group][$key] ?? $default;
        }
        public function set_mock_setting($group, $key, $value) {
            $this->settings[$group][$key] = $value;
        }
    }
}

namespace PerformanceOptimisation\Utils {
    class LoggingUtil {
        public function debug($msg, $context = []) {}
        public function info($msg, $context = []) {}
        public function error($msg, $context = []) {}
    }
}

namespace {
    // Mock WordPress functions
    if (!function_exists('is_admin')) { function is_admin() { return false; } }
    if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return false; } }
    if (!function_exists('is_404')) { function is_404() { return false; } }
    if (!function_exists('wp_normalize_path')) { function wp_normalize_path($path) { return $path; } }
    if (!function_exists('wp_parse_url')) { function wp_parse_url($url, $component = -1) { return parse_url($url, $component); } }
    if (!function_exists('sanitize_text_field')) { function sanitize_text_field($str) { return $str; } }
    if (!function_exists('wp_unslash')) { function wp_unslash($str) { return $str; } }
    if (!function_exists('home_url')) { function home_url() { return 'http://example.com'; } }
    if (!function_exists('add_action')) { function add_action() {} }
    if (!function_exists('WP_Filesystem')) { function WP_Filesystem() { return true; } }
    if (!function_exists('wp_mkdir_p')) { function wp_mkdir_p($dir) { return true; } }
    if (!function_exists('current_time')) { function current_time($type) { return date('Y-m-d H:i:s'); } }
    if (!function_exists('wp_get_current_user')) { function wp_get_current_user() { return (object)['roles' => []]; } }
    if (!function_exists('is_singular')) { function is_singular() { return false; } }
    if (!function_exists('get_post_type')) { function get_post_type() { return 'post'; } }
    
    // Mock global filesystem
    global $wp_filesystem;
    $wp_filesystem = new class {
        public function is_dir($dir) { return true; }
        public function put_contents($file, $content) { return true; }
        public function delete($file) { return true; }
    };

    if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
    if (!defined('WP_CONTENT_DIR')) { define('WP_CONTENT_DIR', '/tmp/wp-content'); }

    // Include PageCacheService
    require_once __DIR__ . '/includes/Services/PageCacheService.php';

    use PerformanceOptimisation\Services\PageCacheService;
    use PerformanceOptimisation\Services\SettingsService;
    use PerformanceOptimisation\Utils\LoggingUtil;

    // Setup
    $settings = new SettingsService();
    $logger = new LoggingUtil();
    $service = new PageCacheService($settings, $logger);

    // Helper to set current URL
    function set_current_url($url) {
        $_SERVER['REQUEST_URI'] = $url;
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    // Helper to test exclusion
    function test_exclusion($url, $exclusions, $expected, $message) {
        global $service, $settings;
        set_current_url($url);
        
        // Enable caching
        $settings->set_mock_setting('cache_settings', 'page_cache_enabled', true);
        
        // Set exclusions
        $settings->set_mock_setting('cache_settings', 'cache_exclusions', $exclusions);

        $result = $service->should_cache_page();
        // If expected is TRUE (should cache), then result should be TRUE.
        // If expected is FALSE (should NOT cache), then result should be FALSE.
        
        if ($result === $expected) {
            echo "[PASS] $message\n";
        } else {
            echo "[FAIL] $message. Expected: " . ($expected ? 'Cache' : 'Skip') . ", Got: " . ($result ? 'Cache' : 'Skip') . "\n";
        }
    }

    echo "Starting Cache Exclusion Verification...\n\n";

    // Test 1: No Exclusions
    test_exclusion('/some-page', [], true, "No exclusions should cache");

    // Test 2: Exact URL Match
    test_exclusion('/no-cache', ['urls' => ['no-cache']], false, "Exact URL match should skip");
    test_exclusion('/cache-me', ['urls' => ['no-cache']], true, "Non-matching URL should cache");

    // Test 3: Wildcard URL Match
    test_exclusion('/shop/product-1', ['urls' => ['shop/*']], false, "Wildcard match 'shop/*' should skip");
    test_exclusion('/blog/post-1', ['urls' => ['shop/*']], true, "Non-matching wildcard should cache");

    // Test 4: Cookie Exclusion
    $_COOKIE['woocommerce_items_in_cart'] = '1';
    test_exclusion('/cart', ['cookies' => ['woocommerce_items_in_cart']], false, "Cookie presence should skip");
    unset($_COOKIE['woocommerce_items_in_cart']);
    test_exclusion('/cart', ['cookies' => ['woocommerce_items_in_cart']], true, "Cookie absence should cache");

    // Test 5: User Agent Exclusion
    $_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';
    test_exclusion('/', ['user_agents' => ['Googlebot']], false, "User Agent match should skip");
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
    test_exclusion('/', ['user_agents' => ['Googlebot']], true, "User Agent non-match should cache");

    echo "\nVerification Completed.\n";
}
