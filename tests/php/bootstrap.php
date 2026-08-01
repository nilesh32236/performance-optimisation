<?php
/**
 * PHPUnit bootstrap for Performance Optimisation plugin.
 *
 * @package PerformanceOptimise\Tests
 */

use function Brain\Monkey\setUp;
use function Brain\Monkey\tearDown;
use function Brain\Monkey\Functions\stubs;

require_once __DIR__ . '/../../vendor/autoload.php';

// Define WP constants used by plugin classes.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', '/tmp/wordpress/wp-content' );
}
if ( ! defined( 'WP_CONTENT_URL' ) ) {
	define( 'WP_CONTENT_URL', 'http://example.com/wp-content' );
}
if ( ! defined( 'WPPO_PLUGIN_PATH' ) ) {
	define( 'WPPO_PLUGIN_PATH', __DIR__ . '/../../' );
}
if ( ! defined( 'WPPO_PLUGIN_URL' ) ) {
	define( 'WPPO_PLUGIN_URL', 'http://example.com/wp-content/plugins/performance-optimisation/' );
}
if ( ! defined( 'WPPO_VERSION' ) ) {
	define( 'WPPO_VERSION', '1.8.0' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'YEAR_IN_SECONDS' ) ) {
	define( 'YEAR_IN_SECONDS', 31536000 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// Mock global $wpdb.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
global $wpdb;
$wpdb                     = new stdClass();
$wpdb->posts              = 'wp_posts';
$wpdb->postmeta           = 'wp_postmeta';
$wpdb->comments           = 'wp_comments';
$wpdb->commentmeta        = 'wp_commentmeta';
$wpdb->options            = 'wp_options';
$wpdb->usermeta           = 'wp_usermeta';
$wpdb->users              = 'wp_users';
$wpdb->terms              = 'wp_terms';
$wpdb->term_taxonomy      = 'wp_term_taxonomy';
$wpdb->termmeta           = 'wp_termmeta';
$wpdb->term_relationships = 'wp_term_relationships';
$wpdb->prefix             = 'wp_';
$wpdb->last_error         = '';
// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

/**
 * Bootstrap trait for PHPUnit test cases.
 */
trait WPPO_Test_Bootstrap {
	/**
	 * Set up BrainMonkey before each test.
	 */
	protected function setUp(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		parent::setUp();
		\Brain\Monkey\setUp();
	}

	/**
	 * Tear down BrainMonkey after each test.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}
}
