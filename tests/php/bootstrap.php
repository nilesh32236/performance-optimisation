<?php
/**
 * PHPUnit bootstrap for Performance Optimisation plugin.
 *
 * @package PerformanceOptimise\Tests
 */

use function Brain\Monkey\setUp;
use function Brain\Monkey\tearDown;
use function Brain\Monkey\Functions\stubs;

// Patchwork reads its patchwork.json configuration from the process working
// directory at init (first Brain\Monkey\setUp() call). Normalize to the
// project root so the suite is deterministic regardless of where PHPUnit is
// launched from (repo root, tests/php/, an IDE, or CI), and fail loudly if the
// required configuration file is missing anywhere.
$project_root   = dirname( __DIR__ );
$patchwork_path = getcwd() . '/patchwork.json';
if ( file_exists( $patchwork_path ) ) {
	$project_root = getcwd();
} elseif ( file_exists( $project_root . '/patchwork.json' ) ) {
	chdir( $project_root );
} else {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Bootstrap fails loudly when patchwork.json is missing.
	trigger_error(
		'patchwork.json not found in the project root. Run PHPUnit from the plugin root, or re-add the file before running the suite.',
		E_USER_ERROR
	);
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/stubs/db-mock.php';

// Load the object-cache drop-in template early so wp_cache_set() and friends
// are declared as real PHP functions BEFORE any Brain Monkey test can
// eval-declare stub versions of them. Otherwise the first test that calls
// Functions\when('wp_cache_set') eval-declares the function, preventing
// object-cache.php from loading later (ObjectCacheTest::setUp()).
// Patchwork can safely redefine() real PHP functions; it cannot un-eval them.
if ( ! function_exists( 'wp_cache_set' ) ) {
	require_once __DIR__ . '/../../templates/object-cache.php';
}

// Provide a default in-memory object cache so the real salted cache helpers
// (which read $GLOBALS['wp_object_cache']) do not fatal when tests touch the
// plugin's cache-backed code paths. ObjectCacheTest replaces this with its own
// richer stub in setUp(); every other test gets a miss-on-read store.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
if ( ! isset( $GLOBALS['wp_object_cache'] ) ) {
	$GLOBALS['wp_object_cache'] = new class() {
		/**
		 * Mimics WP_Object_Cache::get() returning a cache miss.
		 *
		 * @param int|string $key   Cache key.
		 * @param string     $group Cache group.
		 * @param bool       $force Whether to force.
		 * @param bool|null  $found Whether the value was found.
		 * @return false
		 */
		public function get( $key, $group = 'default', $force = false, &$found = null ) {
			$found = false;
			return false;
		}

		/**
		 * Mimics WP_Object_Cache::set() accepting any write.
		 *
		 * @param int|string $key    Cache key.
		 * @param mixed      $data   Cache data.
		 * @param string     $group  Cache group.
		 * @param int        $expire Expiration in seconds.
		 * @return true
		 */
		public function set( $key, $data, $group = 'default', $expire = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			return true;
		}

		/**
		 * Mimics WP_Object_Cache::add() — already-present semantics.
		 *
		 * @param int|string $key    Cache key.
		 * @param mixed      $data   Cache data.
		 * @param string     $group  Cache group.
		 * @param int        $expire Expiration in seconds.
		 * @return true
		 */
		public function add( $key, $data, $group = 'default', $expire = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			return true;
		}

		/**
		 * Mimics WP_Object_Cache::delete() accepting any delete.
		 *
		 * @param int|string $key   Cache key.
		 * @param string     $group Cache group.
		 * @return true
		 */
		public function delete( $key, $group = 'default' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			return true;
		}

		/**
		 * Mimics WP_Object_Cache::flush() as a no-op.
		 *
		 * @return true
		 */
		public function flush() {
			return true;
		}

		/**
		 * Mimics WP_Object_Cache::add_salt() as a no-op.
		 *
		 * @param string $salt Salt value.
		 * @return true
		 */
		public function add_salt( $salt ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return true;
		}
	};
}
// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

// Suppress Patchwork internal redefinition warnings on PHP 8.5 during shutdown.
// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Suppress Patchwork warnings on PHP 8.5
set_error_handler(
	static function ( $errno, $errstr ) {
		if ( str_contains( $errstr, 'Patchwork\Redefinitions' ) ) {
			return true;
		}
		return false;
	}
);

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
	define( 'WPPO_VERSION', '1.9.0' );
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
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}

// WordPress $wpdb result constants used by plugin DB code paths.
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'FS_CHMOD_FILE' ) ) {
	define( 'FS_CHMOD_FILE', 0644 );
}
if ( ! defined( 'FS_CHMOD_DIR' ) ) {
	define( 'FS_CHMOD_DIR', 0755 );
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
		\PerformanceOptimise\Inc\Util::reset_cached_home_urls();
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		if ( class_exists( 'PerformanceOptimise\Inc\Image_Optimisation' ) ) {
			\PerformanceOptimise\Inc\Image_Optimisation::clear_file_exists_cache();
		}
		if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) ) {
			try {
				$ref = new \ReflectionClass( \PerformanceOptimise\Inc\RUM::class );
				if ( $ref->hasProperty( 'shutdown_buffer' ) ) {
					$prop = $ref->getProperty( 'shutdown_buffer' );
					$prop->setAccessible( true );
					$prop->setValue( null, array() );
				}
				if ( $ref->hasProperty( 'shutdown_registered' ) ) {
					$prop = $ref->getProperty( 'shutdown_registered' );
					$prop->setAccessible( true );
					$prop->setValue( null, false );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		// Pre-register frequently used WP functions to avoid "Cannot redeclare"
		// PHP fatal errors when multiple test classes share one process.
		// Brain Monkey eval-declares a PHP function on first when()/stubs()
		// and that declaration persists for the whole process lifetime even
		// after tearDown()/restoreAll().  By registering them here on every
		// setUp() we guarantee the stub is created by the Brain Monkey session
		// that owns this test, so any subsequent when() call in the test body
		// just *reconfigures* the existing stub rather than re-declaring it.
		// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url
		\Brain\Monkey\Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		// phpcs:enable WordPress.WP.AlternativeFunctions.parse_url_parse_url
		\Brain\Monkey\Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				$path = str_replace( '\\', '/', (string) $path );
				$path = preg_replace( '|(?<=.)/+|', '/', $path );
				if ( str_starts_with( $path, '//' ) ) {
					$path = '/' . ltrim( $path, '/' );
				}
				return $path;
			}
		);
		\Brain\Monkey\Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		\Brain\Monkey\Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		\Brain\Monkey\Functions\when( 'WP_Filesystem' )->justReturn( false );
		\Brain\Monkey\Functions\when( 'sanitize_text_field' )->returnArg();
		\Brain\Monkey\Functions\when( 'wp_unslash' )->returnArg();
		\Brain\Monkey\Functions\when( 'content_url' )->alias(
			static function ( $path = '' ) {
				return 'http://example.com/wp-content' . (string) $path;
			}
		);
		\Brain\Monkey\Functions\when( 'trailingslashit' )->returnArg();
		\Brain\Monkey\Functions\when( 'wp_maybe_inline_styles' )->justReturn( '' );
		\Brain\Monkey\Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		\Brain\Monkey\Functions\when( '__' )->returnArg( 1 );
		\Brain\Monkey\Functions\when( 'esc_html__' )->returnArg( 1 );
		\Brain\Monkey\Functions\when( 'esc_html' )->returnArg( 1 );
		\Brain\Monkey\Functions\when( 'esc_url_raw' )->returnArg();
	}

	/**
	 * Tear down BrainMonkey after each test.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}
}
