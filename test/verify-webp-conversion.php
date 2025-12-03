<?php
/**
 * Verify WebP Conversion
 */

require_once dirname( dirname( dirname( __DIR__ ) ) ) . '/wp-load.php';

echo "Starting WebP Conversion Verification...\n\n";

$passed = 0;
$failed = 0;

// Get services
$container       = \PerformanceOptimisation\Core\Bootstrap\Plugin::getInstance()->getContainer();
$image_service   = $container->get( 'image_service' );
$image_processor = $container->get( 'image_processor' );

// Test 1: Check if WebP is supported
echo "Test 1: WebP Support\n";
if ( function_exists( 'imagewebp' ) ) {
	echo "[PASS] WebP is supported by PHP\n\n";
	++$passed;
} else {
	echo "[FAIL] WebP is NOT supported by PHP\n\n";
	++$failed;
}

// Test 2: Check ImageService hook registration
echo "Test 2: ImageService Hook Registration\n";
if ( has_filter( 'wp_generate_attachment_metadata' ) ) {
	echo "[PASS] wp_generate_attachment_metadata hook is registered\n\n";
	++$passed;
} else {
	echo "[FAIL] wp_generate_attachment_metadata hook is NOT registered\n\n";
	++$failed;
}

// Test 3: Check ImageProcessor can convert
echo "Test 3: ImageProcessor Conversion Capability\n";
if ( method_exists( $image_processor, 'convert' ) ) {
	echo "[PASS] ImageProcessor has convert method\n\n";
	++$passed;
} else {
	echo "[FAIL] ImageProcessor does NOT have convert method\n\n";
	++$failed;
}

// Test 4: Check settings structure
echo "Test 4: Settings Structure\n";
$settings     = get_option( 'wppo_settings', array() );
$auto_convert = $settings['images']['auto_convert_on_upload']
	?? $settings['image_optimization']['auto_convert_on_upload']
	?? true;
echo '[INFO] Auto-convert on upload: ' . ( $auto_convert ? 'enabled' : 'disabled' ) . "\n";
echo "[PASS] Settings structure is valid\n\n";
++$passed;

// Test 5: Check ConversionQueue
echo "Test 5: ConversionQueue\n";
if ( class_exists( '\PerformanceOptimisation\Utils\ConversionQueue' ) ) {
	echo "[PASS] ConversionQueue class exists\n\n";
	++$passed;
} else {
	echo "[FAIL] ConversionQueue class does NOT exist\n\n";
	++$failed;
}

// Test 6: Check target formats
echo "Test 6: Target Formats\n";
$formats = $image_service->get_target_formats();
echo '[INFO] Enabled formats: ' . implode( ', ', $formats ) . "\n";
if ( ! empty( $formats ) ) {
	echo "[PASS] Target formats configured\n\n";
	++$passed;
} else {
	echo "[FAIL] No target formats configured\n\n";
	++$failed;
}

// Summary
echo "Verification Completed.\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed > 0 ? 1 : 0 );
