<?php
/**
 * Verification script for Font Optimization Functionality
 * 
 * Tests the logic in FontOptimizationService.
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
        public function info($msg, $context = []) { echo "[INFO] $msg\n"; }
        public function error($msg, $context = []) { echo "[ERROR] $msg\n"; }
    }
}

namespace {
    // Mock WordPress functions
    if (!defined('ABSPATH')) { define('ABSPATH', '/tmp'); }
    function esc_url($url) { return $url; }
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {}
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {}

    // Include FontOptimizationService
    require_once __DIR__ . '/includes/Services/FontOptimizationService.php';

    use PerformanceOptimisation\Services\FontOptimizationService;
    use PerformanceOptimisation\Services\SettingsService;
    use PerformanceOptimisation\Utils\LoggingUtil;

    // Setup
    $settings = new SettingsService();
    $logger = new LoggingUtil();
    $service = new FontOptimizationService($settings, $logger);

    echo "Starting Font Optimization Verification...\n\n";

    // Test 1: Preload Fonts
    echo "Test 1: Preload Fonts\n";
    $settings->set_mock_setting('preloading', 'preload_fonts', ['https://example.com/font.woff2']);
    ob_start();
    $service->preload_fonts();
    $output = ob_get_clean();
    
    if (strpos($output, '<link rel=\'preload\' href=\'https://example.com/font.woff2\' as=\'font\' type=\'font/woff2\' crossorigin>') !== false) {
        echo "[PASS] Preload tag generated correctly\n";
    } else {
        echo "[FAIL] Preload tag generation failed. Got: $output\n";
    }

    // Test 2: Add display:swap
    echo "\nTest 2: Add display:swap\n";
    $html = '<link rel=\'stylesheet\' id=\'google-fonts-css\' href=\'https://fonts.googleapis.com/css?family=Roboto:400,700\' type=\'text/css\' media=\'all\' />';
    $modified = $service->add_font_display_swap($html, 'google-fonts-css');
    
    if (strpos($modified, 'display=swap') !== false) {
        echo "[PASS] display:swap added to Google Fonts URL\n";
    } else {
        echo "[FAIL] display:swap not added. Got: $modified\n";
    }

    // Test 3: Preconnect
    echo "\nTest 3: Preconnect\n";
    $urls = $service->add_google_fonts_preconnect([], 'preconnect');
    if (in_array('https://fonts.gstatic.com', $urls) && in_array('https://fonts.googleapis.com', $urls)) {
        echo "[PASS] Preconnect URLs added\n";
    } else {
        echo "[FAIL] Preconnect URLs missing\n";
    }

    echo "\nVerification Completed.\n";
}
