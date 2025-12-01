<?php
/**
 * Verification script for Exclusion Functionality
 * 
 * Tests the matching logic in AssetOptimizationService.
 */

namespace PerformanceOptimisation\Optimizers {
    class CssOptimizer { public function optimizeFile() { return ['success' => false]; } }
    class JsOptimizer { public function process_file() { return ''; } }
    class HtmlOptimizer { public function optimize() { return ''; } }
}

namespace PerformanceOptimisation\Utils {
    class FileSystemUtil { 
        public static function getLocalPath($url) { return '/tmp/file'; } 
        public static function pathToUrl($path) { return 'http://example.com/file'; }
        public static function createDirectory($dir) {}
        public static function writeFile($path, $content) {}
    }
    class LoggingUtil {}
    class ValidationUtil {}
    class PerformanceUtil {}
    class CacheUtil {}
}

namespace Psr\Container {
    interface ContainerInterface {
        public function get(string $id);
        public function has(string $id): bool;
    }
}

namespace {
    // Mock WordPress functions
    if (!function_exists('is_admin')) { function is_admin() { return false; } }
    if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return false; } }
    if (!function_exists('add_filter')) { function add_filter() {} }
    if (!function_exists('add_action')) { function add_action() {} }
    if (!function_exists('get_option')) { function get_option($key, $default = false) { return $default; } }
    if (!function_exists('is_feed')) { function is_feed() { return false; } }
    if (!defined('ABSPATH')) { define('ABSPATH', '/tmp'); }
    if (!defined('WP_CONTENT_DIR')) { define('WP_CONTENT_DIR', '/tmp'); }

    // Include Interfaces
    require_once __DIR__ . '/includes/Interfaces/ServiceContainerInterface.php';
    require_once __DIR__ . '/includes/Interfaces/SettingsServiceInterface.php';
    require_once __DIR__ . '/includes/Interfaces/OptimizerInterface.php';
    
    // Include Services
    require_once __DIR__ . '/includes/Services/AssetOptimizationService.php';
    require_once __DIR__ . '/includes/Services/SettingsService.php';

    use PerformanceOptimisation\Services\AssetOptimizationService;
    use PerformanceOptimisation\Services\SettingsService;
    use PerformanceOptimisation\Optimizers\CssOptimizer;
    use PerformanceOptimisation\Optimizers\JsOptimizer;
    use PerformanceOptimisation\Optimizers\HtmlOptimizer;

    // Mock SettingsService
    class MockSettingsService extends SettingsService {
        private $mock_settings = [];

        public function __construct() {}

        public function set_mock_setting($group, $key, $value) {
            $this->mock_settings[$group][$key] = $value;
        }

        public function get_setting(string $group, string $key, $default = null) {
            return $this->mock_settings[$group][$key] ?? $default;
        }
    }

    // Instantiate service with mocks
    $settings_service = new MockSettingsService();
    
    // We need a mock container or null since we are mocking dependencies manually
    // But AssetOptimizationService constructor expects specific classes, not container.
    // Wait, AssetOptimizationService constructor:
    // public function __construct(
    // 	SettingsService $settings_service,
    // 	CssOptimizer $css_optimizer,
    // 	JsOptimizer $js_optimizer,
    // 	HtmlOptimizer $html_optimizer
    // )
    
    $service = new AssetOptimizationService(
        $settings_service,
        new CssOptimizer(),
        new JsOptimizer(),
        new HtmlOptimizer()
    );

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('is_excluded');
    $method->setAccessible(true);

    function test_match($item, $patterns, $expected, $message) {
        global $service, $method;
        $result = $method->invoke($service, $item, $patterns);
        if ($result === $expected) {
            echo "[PASS] $message\n";
        } else {
            echo "[FAIL] $message. Expected: " . ($expected ? 'true' : 'false') . ", Got: " . ($result ? 'true' : 'false') . "\n";
        }
    }

    echo "Starting Exclusion Logic Verification...\n\n";

    // Test 1: Exact Match
    test_match('jquery', ['jquery'], true, "Exact match 'jquery'");
    test_match('jquery', ['other'], false, "No match 'jquery' vs 'other'");

    // Test 2: Wildcard Match
    test_match('wp-block-library', ['wp-*'], true, "Wildcard match 'wp-*' matches 'wp-block-library'");
    test_match('custom-style', ['*-style'], true, "Wildcard match '*-style' matches 'custom-style'");
    test_match('script.js', ['*.js'], true, "Wildcard match '*.js' matches 'script.js'");
    test_match('style.css', ['*.js'], false, "Wildcard match '*.js' does not match 'style.css'");

    // Test 3: Regex Match
    test_match('custom-123-script', ['/custom-\d+-script/'], true, "Regex match '/custom-\d+-script/'");
    test_match('custom-abc-script', ['/custom-\d+-script/'], false, "Regex match fail");

    // Test 4: Substring Match (Legacy)
    test_match('https://example.com/wp-content/plugins/my-plugin/style.css', ['my-plugin'], true, "Substring match 'my-plugin'");

    // Test 5: Multiple Patterns
    $patterns = ['jquery', 'wp-*', '/custom-\d+/'];
    test_match('jquery', $patterns, true, "Multiple patterns: Exact match");
    test_match('wp-embed', $patterns, true, "Multiple patterns: Wildcard match");
    test_match('custom-999', $patterns, true, "Multiple patterns: Regex match");
    test_match('other', $patterns, false, "Multiple patterns: No match");

    echo "\nVerification Completed.\n";
}


