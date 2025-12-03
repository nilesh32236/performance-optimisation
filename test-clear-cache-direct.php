<?php
/**
 * Direct Cache Clear Test (No Auth Required)
 */

// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';

echo "=== Direct Cache Clear Test ===\n\n";

// Get cache directory
$cache_dir = WP_CONTENT_DIR . '/cache/wppo/pages';

echo "1. Checking cache directory:\n";
echo "   Path: $cache_dir\n";

if (!file_exists($cache_dir)) {
	echo "   ℹ️  Cache directory doesn't exist\n";
	echo "   Creating test cache file...\n";
	
	// Create test cache
	wp_mkdir_p($cache_dir . '/localhost/awm');
	file_put_contents($cache_dir . '/localhost/awm/test.html', '<html>Test</html>');
	file_put_contents($cache_dir . '/localhost/awm/test.html.gz', gzencode('<html>Test</html>'));
	
	echo "   ✅ Test cache files created\n\n";
}

// Count files before
$files_before = [];
if (file_exists($cache_dir)) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ($iterator as $file) {
		$files_before[] = $file->getPathname();
	}
}

echo "2. Files before clear: " . count($files_before) . "\n";
if (count($files_before) > 0) {
	echo "   Sample files:\n";
	foreach (array_slice($files_before, 0, 3) as $file) {
		echo "   - " . basename($file) . "\n";
	}
}
echo "\n";

// Get PageCacheService
echo "3. Loading PageCacheService:\n";
$container = \PerformanceOptimisation\Core\ServiceContainer::getInstance();

if (!$container->has('page_cache_service')) {
	echo "   ❌ PageCacheService not registered\n";
	exit(1);
}

$page_cache = $container->get('page_cache_service');
echo "   ✅ PageCacheService loaded\n\n";

// Clear cache
echo "4. Clearing cache:\n";
try {
	$result = $page_cache->clear_all_cache();
	
	if ($result) {
		echo "   ✅ clear_all_cache() returned true\n\n";
	} else {
		echo "   ❌ clear_all_cache() returned false\n\n";
	}
} catch (Exception $e) {
	echo "   ❌ Exception: " . $e->getMessage() . "\n\n";
	exit(1);
}

// Count files after
echo "5. Verifying cache cleared:\n";
if (!file_exists($cache_dir)) {
	echo "   ✅ Cache directory removed completely\n";
	echo "   Files after clear: 0\n";
} else {
	$files_after = [];
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ($iterator as $file) {
		$files_after[] = $file->getPathname();
	}
	
	echo "   Files after clear: " . count($files_after) . "\n";
	
	if (count($files_after) == 0) {
		echo "   ✅ All cache files removed\n";
	} else {
		echo "   ⚠️  Some files still remain:\n";
		foreach (array_slice($files_after, 0, 5) as $file) {
			echo "   - " . basename($file) . "\n";
		}
	}
}

echo "\n=== Summary ===\n";
echo "Files before: " . count($files_before) . "\n";
echo "Files after: " . (file_exists($cache_dir) ? count($files_after) : 0) . "\n";

if (!file_exists($cache_dir) || count($files_after) == 0) {
	echo "✅ Cache clear is working correctly!\n";
} else {
	echo "❌ Cache clear is NOT working - files remain\n";
}
