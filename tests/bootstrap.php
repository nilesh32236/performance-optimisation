<?php
/**
 * PHPUnit Bootstrap
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs.php';



// Define WP constants if not defined
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', '/tmp/wordpress/wp-content' );
}

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', '/tmp/wordpress/wp-content/plugins' );
}
