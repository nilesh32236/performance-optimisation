<?php
/**
 * Verification script for Performance Optimisation plugin.
 */

// Ensure we are in WordPress context
if ( ! defined( 'ABSPATH' ) ) {
    require_once 'wp-load.php';
}

echo "Starting Verification...\n";

// 1. Check Service Registration
$container = \PerformanceOptimisation\Core\ServiceContainer::getInstance();
if ( $container->has( 'asset_optimization_service' ) ) {
    echo "[PASS] AssetOptimizationService is registered.\n";
} else {
    echo "[FAIL] AssetOptimizationService is NOT registered.\n";
    exit( 1 );
}

$service = $container->get( 'asset_optimization_service' );

// 2. Verify CSS Minification
$original_css_tag = "<link rel='stylesheet' id='test-css' href='" . content_url( 'plugins/performance-optimisation/admin/src/styles/main.css' ) . "' media='all' />";
$handle = 'test-css';
$href = content_url( 'plugins/performance-optimisation/admin/src/styles/main.css' );
$media = 'all';

// Ensure the file exists for testing
$css_path = WP_PLUGIN_DIR . '/performance-optimisation/admin/src/styles/main.css';
if ( ! file_exists( $css_path ) ) {
    echo "[WARN] Test CSS file not found at $css_path. Creating dummy file.\n";
    if (!is_dir(dirname($css_path))) mkdir(dirname($css_path), 0755, true);
    file_put_contents( $css_path, "body { color: red; } \n div { display: block; }" );
}

$optimized_css_tag = $service->optimize_css_tag( $original_css_tag, $handle, $href, $media );

if ( strpos( $optimized_css_tag, '/cache/wppo/min/css/' ) !== false ) {
    echo "[PASS] CSS Minification: Tag modified to point to cache.\n";
    echo "Original: $original_css_tag\n";
    echo "Optimized: $optimized_css_tag\n";
} else {
    echo "[FAIL] CSS Minification: Tag NOT modified.\n";
    echo "Optimized: $optimized_css_tag\n";
}

// 3. Verify JS Optimization (Defer/Delay)
// Enable Defer and Delay for testing
update_option( 'wppo_settings', array_merge( get_option( 'wppo_settings', array() ), array(
    'minification' => array(
        'minify_js' => true,
        'defer_js' => true,
        'delay_js' => true,
    ),
    'advanced' => array(
        'defer_js' => true,
        'delay_js' => true,
    )
) ) );

// Re-init service to pick up settings if needed (though settings service usually reads fresh)
// But AssetOptimizationService reads settings in hooks, so it should be fine.

$original_js_tag = "<script src='" . content_url( 'plugins/performance-optimisation/admin/src/lazyload.js' ) . "'></script>";
$handle_js = 'test-js';
$src_js = content_url( 'plugins/performance-optimisation/admin/src/lazyload.js' );

// Ensure JS file exists
$js_path = WP_PLUGIN_DIR . '/performance-optimisation/admin/src/lazyload.js';
if ( ! file_exists( $js_path ) ) {
     echo "[WARN] Test JS file not found at $js_path. Creating dummy file.\n";
     file_put_contents( $js_path, "console.log('test');" );
}

$optimized_js_tag = $service->optimize_js_tag( $original_js_tag, $handle_js, $src_js );

if ( strpos( $optimized_js_tag, 'defer="defer"' ) !== false ) {
    echo "[PASS] JS Defer: 'defer' attribute added.\n";
} else {
    echo "[FAIL] JS Defer: 'defer' attribute MISSING.\n";
}

if ( strpos( $optimized_js_tag, 'data-src' ) !== false && strpos( $optimized_js_tag, 'type="wppo/javascript"' ) !== false ) {
    echo "[PASS] JS Delay: 'data-src' and type attribute added.\n";
} else {
    echo "[FAIL] JS Delay: Attributes MISSING.\n";
}

// 4. Check Cache Files
$cache_dir = WP_CONTENT_DIR . '/cache/wppo/min/';
if ( is_dir( $cache_dir ) ) {
    echo "[PASS] Cache directory exists: $cache_dir\n";
    $files = glob( $cache_dir . '*/*.*' );
    if ( count( $files ) > 0 ) {
        echo "[PASS] Cache files found: " . count( $files ) . "\n";
    } else {
        echo "[WARN] No cache files found yet (might be expected if only dry run).\n";
    }
} else {
    echo "[FAIL] Cache directory does NOT exist: $cache_dir\n";
}

echo "Verification Complete.\n";
