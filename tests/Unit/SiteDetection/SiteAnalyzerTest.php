<?php
/**
 * Tests for SiteAnalyzer class
 *
 * @package PerformanceOptimisation\Tests\Unit\SiteDetection
 */

namespace PerformanceOptimisation\Tests\Unit\SiteDetection;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Core\SiteDetection\SiteAnalyzer;

/**
 * Test case for SiteAnalyzer class.
 */
class SiteAnalyzerTest extends TestCase {

	/**
	 * SiteAnalyzer instance.
	 *
	 * @var SiteAnalyzer
	 */
	private SiteAnalyzer $analyzer;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->analyzer = new SiteAnalyzer();
	}

	/**
	 * Test site analysis returns expected structure.
	 */
	public function test_analyze_site_returns_expected_structure(): void {
		// Mock WordPress functions
		$this->mockWordPressFunctions();

		$analysis = $this->analyzer->analyze_site();

		$this->assertIsArray( $analysis );
		$this->assertArrayHasKey( 'hosting', $analysis );
		$this->assertArrayHasKey( 'WordPress', $analysis );
		$this->assertArrayHasKey( 'plugins', $analysis );
		$this->assertArrayHasKey( 'theme', $analysis );
		$this->assertArrayHasKey( 'content', $analysis );
		$this->assertArrayHasKey( 'performance', $analysis );
		$this->assertArrayHasKey( 'compatibility', $analysis );
		$this->assertArrayHasKey( 'recommendations', $analysis );
		$this->assertArrayHasKey( 'conflicts', $analysis );
		$this->assertArrayHasKey( 'timestamp', $analysis );
	}

	/**
	 * Test hosting environment analysis.
	 */
	public function test_hosting_analysis_structure(): void {
		$this->mockWordPressFunctions();

		$analysis = $this->analyzer->analyze_site();
		$hosting  = $analysis['hosting'];

		$this->assertArrayHasKey( 'server_software', $hosting );
		$this->assertArrayHasKey( 'php_version', $hosting );
		$this->assertArrayHasKey( 'php_extensions', $hosting );
		$this->assertArrayHasKey( 'memory_limit', $hosting );
		$this->assertArrayHasKey( 'hosting_provider', $hosting );
		$this->assertArrayHasKey( 'ssl_enabled', $hosting );
	}

	/**
	 * Test WordPress analysis structure.
	 */
	public function test_wordpress_analysis_structure(): void {
		$this->mockWordPressFunctions();

		$analysis  = $this->analyzer->analyze_site();
		$wordpress = $analysis['wordpress'];

		$this->assertArrayHasKey( 'version', $wordpress );
		$this->assertArrayHasKey( 'multisite', $wordpress );
		$this->assertArrayHasKey( 'debug_enabled', $wordpress );
		$this->assertArrayHasKey( 'cache_enabled', $wordpress );
		$this->assertArrayHasKey( 'object_cache', $wordpress );
	}

	/**
	 * Test plugin analysis structure.
	 */
	public function test_plugin_analysis_structure(): void {
		$this->mockWordPressFunctions();

		$analysis = $this->analyzer->analyze_site();
		$plugins  = $analysis['plugins'];

		$this->assertArrayHasKey( 'total_count', $plugins );
		$this->assertArrayHasKey( 'plugins', $plugins );
		$this->assertArrayHasKey( 'performance_plugins', $plugins );
		$this->assertArrayHasKey( 'conflicts', $plugins );
		$this->assertIsInt( $plugins['total_count'] );
		$this->assertIsArray( $plugins['plugins'] );
		$this->assertIsArray( $plugins['performance_plugins'] );
		$this->assertIsArray( $plugins['conflicts'] );
	}

	/**
	 * Test content analysis structure.
	 */
	public function test_content_analysis_structure(): void {
		$this->mockWordPressFunctions();

		$analysis = $this->analyzer->analyze_site();
		$content  = $analysis['content'];

		$this->assertArrayHasKey( 'post_count', $content );
		$this->assertArrayHasKey( 'page_count', $content );
		$this->assertArrayHasKey( 'media_count', $content );
		$this->assertArrayHasKey( 'comment_count', $content );
		$this->assertIsInt( $content['post_count'] );
		$this->assertIsInt( $content['media_count'] );
	}

	/**
	 * Test compatibility analysis structure.
	 */
	public function test_compatibility_analysis_structure(): void {
		$this->mockWordPressFunctions();

		$analysis      = $this->analyzer->analyze_site();
		$compatibility = $analysis['compatibility'];

		$this->assertArrayHasKey( 'page_caching', $compatibility );
		$this->assertArrayHasKey( 'object_caching', $compatibility );
		$this->assertArrayHasKey( 'image_optimization', $compatibility );
		$this->assertArrayHasKey( 'minification', $compatibility );

		// Test structure of compatibility items
		foreach ( $compatibility as $feature => $compat ) {
			$this->assertArrayHasKey( 'compatible', $compat );
			$this->assertArrayHasKey( 'requirements', $compat );
			$this->assertArrayHasKey( 'conflicts', $compat );
			$this->assertArrayHasKey( 'score', $compat );
			$this->assertIsBool( $compat['compatible'] );
			$this->assertIsArray( $compat['requirements'] );
			$this->assertIsArray( $compat['conflicts'] );
			$this->assertIsInt( $compat['score'] );
			$this->assertGreaterThanOrEqual( 0, $compat['score'] );
			$this->assertLessThanOrEqual( 100, $compat['score'] );
		}
	}

	/**
	 * Test cache clearing functionality.
	 */
	public function test_clear_cache(): void {
		$this->mockWordPressFunctions();

		// First call should generate analysis
		$analysis1 = $this->analyzer->analyze_site();
		$this->assertIsArray( $analysis1 );

		// Clear cache
		$result = $this->analyzer->clear_cache();
		$this->assertTrue( $result );

		// Second call should generate fresh analysis
		$analysis2 = $this->analyzer->analyze_site();
		$this->assertIsArray( $analysis2 );
	}

	/**
	 * Test server software detection.
	 */
	public function test_server_software_detection(): void {
		// Test Apache detection
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.41 (Ubuntu)';
		$this->assertEquals( 'Apache', $this->getServerSoftware() );

		// Test Nginx detection
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.18.0';
		$this->assertEquals( 'Nginx', $this->getServerSoftware() );

		// Test LiteSpeed detection
		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
		$this->assertEquals( 'LiteSpeed', $this->getServerSoftware() );

		// Test unknown server
		$_SERVER['SERVER_SOFTWARE'] = 'CustomServer/1.0';
		$this->assertEquals( 'Unknown', $this->getServerSoftware() );
	}

	/**
	 * Test hosting provider detection.
	 */
	public function test_hosting_provider_detection(): void {
		// Test WP Engine detection
		$_SERVER['SERVER_NAME'] = 'example.wpengine.com';
		$this->assertEquals( 'wpengine', $this->getHostingProvider() );

		// Test SiteGround detection
		$_SERVER['HTTP_HOST'] = 'example.siteground.com';
		$this->assertEquals( 'siteground', $this->getHostingProvider() );

		// Test unknown provider
		$_SERVER['SERVER_NAME'] = 'example.com';
		$_SERVER['HTTP_HOST']   = 'example.com';
		$this->assertEquals( 'Unknown', $this->getHostingProvider() );
	}

	/**
	 * Test PHP extension detection.
	 */
	public function test_php_extension_detection(): void {
		$this->mockWordPressFunctions();

		$analysis   = $this->analyzer->analyze_site();
		$extensions = $analysis['hosting']['php_extensions'];

		$this->assertIsArray( $extensions );
		$this->assertArrayHasKey( 'gd', $extensions );
		$this->assertArrayHasKey( 'curl', $extensions );
		$this->assertArrayHasKey( 'zip', $extensions );
		$this->assertIsBool( $extensions['gd'] );
		$this->assertIsBool( $extensions['curl'] );
	}

	/**
	 * Mock WordPress functions for testing.
	 */
	private function mockWordPressFunctions(): void {
		// Mock global variables
		global $wp_version, $wpdb;
		$wp_version = '6.2.0';

		// Mock WordPress functions
		if ( ! function_exists( 'get_option' ) ) {
			function get_option( $option, $default = false ) {
				switch ( $option ) {
					case 'active_plugins':
						return array( 'test-plugin/test-plugin.php' );
					case 'permalink_structure':
						return '/%postname%/';
					case 'timezone_string':
						return 'UTC';
					default:
						return $default;
				}
			}
		}

		if ( ! function_exists( 'is_multisite' ) ) {
			function is_multisite() {
				return false;
			}
		}

		if ( ! function_exists( 'is_ssl' ) ) {
			function is_ssl() {
				return true;
			}
		}

		if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
			function wp_using_ext_object_cache() {
				return false;
			}
		}

		if ( ! function_exists( 'wp_count_posts' ) ) {
			function wp_count_posts( $type = 'post' ) {
				$obj          = new \stdClass();
				$obj->publish = 100;
				$obj->inherit = 50;
				return $obj;
			}
		}

		if ( ! function_exists( 'wp_count_comments' ) ) {
			function wp_count_comments() {
				$obj           = new \stdClass();
				$obj->approved = 200;
				return $obj;
			}
		}

		if ( ! function_exists( 'get_locale' ) ) {
			function get_locale() {
				return 'en_US';
			}
		}

		if ( ! function_exists( 'current_time' ) ) {
			function current_time( $type ) {
				return time();
			}
		}

		if ( ! function_exists( 'wp_get_theme' ) ) {
			function wp_get_theme() {
				return new MockTheme();
			}
		}

		if ( ! function_exists( 'is_child_theme' ) ) {
			function is_child_theme() {
				return false;
			}
		}

		if ( ! function_exists( 'current_theme_supports' ) ) {
			function current_theme_supports( $feature ) {
				return true;
			}
		}

		if ( ! function_exists( 'get_users' ) ) {
			function get_users( $args ) {
				return array( 'admin1', 'admin2' );
			}
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			function get_plugin_data( $plugin_file ) {
				return array(
					'Name'        => 'Test Plugin',
					'Version'     => '1.0.0',
					'Author'      => 'Test Author',
					'Description' => 'Test Description',
				);
			}
		}

		if ( ! function_exists( 'get_transient' ) ) {
			function get_transient( $transient ) {
				return false;
			}
		}

		if ( ! function_exists( 'set_transient' ) ) {
			function set_transient( $transient, $value, $expiration ) {
				return true;
			}
		}

		if ( ! function_exists( 'delete_transient' ) ) {
			function delete_transient( $transient ) {
				return true;
			}
		}

		// Mock wpdb
		if ( ! isset( $wpdb ) ) {
			$wpdb = new MockWpdb();
		}
	}

	/**
	 * Helper method to test server software detection.
	 */
	private function getServerSoftware(): string {
		$reflection = new \ReflectionClass( $this->analyzer );
		$method     = $reflection->getMethod( 'detect_server_software' );
		$method->setAccessible( true );
		return $method->invoke( $this->analyzer );
	}

	/**
	 * Helper method to test hosting provider detection.
	 */
	private function getHostingProvider(): string {
		$reflection = new \ReflectionClass( $this->analyzer );
		$method     = $reflection->getMethod( 'detect_hosting_provider' );
		$method->setAccessible( true );
		return $method->invoke( $this->analyzer );
	}
}

/**
 * Mock theme class for testing.
 */
class MockTheme {
	public function get( $header ) {
		switch ( $header ) {
			case 'Name':
				return 'Test Theme';
			case 'Version':
				return '1.0.0';
			case 'Author':
				return 'Test Author';
			default:
				return '';
		}
	}

	public function get_template() {
		return 'test-theme';
	}

	public function get_stylesheet() {
		return 'test-theme';
	}
}

/**
 * Mock wpdb class for testing.
 */
class MockWpdb {
	public $num_queries = 10;
	public $posts       = 'wp_posts';
	public $postmeta    = 'wp_postmeta';

	public function get_var( $query ) {
		return 1000; // Mock average post size
	}

	public function get_results( $query, $output = OBJECT ) {
		return array(
			array(
				'format' => 'jpeg',
				'count'  => 50,
			),
			array(
				'format' => 'png',
				'count'  => 30,
			),
		);
	}

	public function prepare( $query, ...$args ) {
		return $query;
	}
}
