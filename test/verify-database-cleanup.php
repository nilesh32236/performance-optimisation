<?php
/**
 * Verification script for Database Cleanup Functionality
 * 
 * Tests the cleanup logic in DatabaseOptimizationService.
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
    if (!defined('ARRAY_N')) { define('ARRAY_N', 'ARRAY_N'); }
    if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
    
    // Mock wpdb
    class MockWPDB {
        public $posts = 'wp_posts';
        public $term_relationships = 'wp_term_relationships';
        public $postmeta = 'wp_postmeta';
        public $comments = 'wp_comments';
        public $commentmeta = 'wp_commentmeta';
        
        public function query($query) {
            // Simple logic to return a number based on the query type
            if (strpos($query, 'DELETE') !== false) {
                return 5; // Simulate 5 items deleted
            }
            if (strpos($query, 'OPTIMIZE') !== false) {
                return true;
            }
            return 0;
        }
        
        public function get_results($query, $output = OBJECT) {
            if (strpos($query, 'SHOW TABLES') !== false) {
                return [['wp_posts'], ['wp_comments'], ['wp_options']];
            }
            return [];
        }
    }
    
    global $wpdb;
    $wpdb = new MockWPDB();

    // Include DatabaseOptimizationService
    require_once __DIR__ . '/includes/Services/DatabaseOptimizationService.php';

    use PerformanceOptimisation\Services\DatabaseOptimizationService;
    use PerformanceOptimisation\Services\SettingsService;
    use PerformanceOptimisation\Utils\LoggingUtil;

    // Setup
    $settings = new SettingsService();
    $logger = new LoggingUtil();
    $service = new DatabaseOptimizationService($settings, $logger);

    echo "Starting Database Cleanup Verification...\n\n";

    // Test 1: Cleanup Revisions
    echo "Test 1: Cleanup Revisions\n";
    $settings->set_mock_setting('database', 'cleanup', ['revisions' => true]);
    $result = $service->run_cleanup();
    if (isset($result['revisions']) && $result['revisions'] === 5) {
        echo "[PASS] Revisions cleanup executed\n";
    } else {
        echo "[FAIL] Revisions cleanup failed\n";
    }

    // Test 2: Cleanup Spam
    echo "\nTest 2: Cleanup Spam\n";
    $settings->set_mock_setting('database', 'cleanup', ['spam' => true]);
    $result = $service->run_cleanup();
    if (isset($result['spam']) && $result['spam'] === 5) {
        echo "[PASS] Spam cleanup executed\n";
    } else {
        echo "[FAIL] Spam cleanup failed\n";
    }

    // Test 3: Cleanup Trash
    echo "\nTest 3: Cleanup Trash\n";
    $settings->set_mock_setting('database', 'cleanup', ['trash' => true]);
    $result = $service->run_cleanup();
    if (isset($result['trash']) && $result['trash'] === 10) { // 5 posts + 5 comments
        echo "[PASS] Trash cleanup executed\n";
    } else {
        echo "[FAIL] Trash cleanup failed. Got: " . ($result['trash'] ?? 'null') . "\n";
    }

    // Test 4: Optimize Tables
    echo "\nTest 4: Optimize Tables\n";
    $settings->set_mock_setting('database', 'cleanup', ['optimize_tables' => true]);
    $result = $service->run_cleanup();
    if (isset($result['optimize_tables']) && $result['optimize_tables'] === 3) {
        echo "[PASS] Table optimization executed\n";
    } else {
        echo "[FAIL] Table optimization failed\n";
    }

    echo "\nVerification Completed.\n";
}
