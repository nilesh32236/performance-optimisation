<?php
/**
 * Plugin Bootstrap Unit Tests
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Tests\Unit\Core\Bootstrap;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Core\Bootstrap\Plugin;
use PerformanceOptimisation\Interfaces\ContainerInterface;
use PerformanceOptimisation\Exceptions\PluginException;

/**
 * Test cases for the Plugin bootstrap class
 *
 * @since 1.1.0
 */
class PluginTest extends TestCase {

	/**
	 * Plugin instance
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Set up test environment
	 *
	 * @since 1.1.0
	 */
	protected function setUp(): void {
		parent::setUp();

		// Mock WordPress functions
		if ( ! function_exists( 'plugin_dir_path' ) ) {
			function plugin_dir_path( $file ) {
				return dirname( $file ) . '/';
			}
		}

		if ( ! function_exists( 'plugin_dir_url' ) ) {
			function plugin_dir_url( $file ) {
				return 'http://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
			}
		}

		if ( ! function_exists( 'do_action' ) ) {
			function do_action( $hook, ...$args ) {
				// Mock do_action
			}
		}

		if ( ! function_exists( 'is_admin' ) ) {
			function is_admin() {
				return false;
			}
		}

		if ( ! function_exists( 'load_plugin_textdomain' ) ) {
			function load_plugin_textdomain( $domain, $deprecated, $plugin_rel_path ) {
				return true;
			}
		}

		$this->plugin = Plugin::get_instance( '/path/to/plugin.php', '1.1.0' );
	}

	/**
	 * Test singleton pattern
	 *
	 * @since 1.1.0
	 */
	public function test_singleton_pattern(): void {
		$instance1 = Plugin::get_instance();
		$instance2 = Plugin::get_instance();

		$this->assertSame( $instance1, $instance2 );
	}

	/**
	 * Test get version
	 *
	 * @since 1.1.0
	 */
	public function test_get_version(): void {
		$version = $this->plugin->get_version();
		$this->assertEquals( '1.1.0', $version );
	}

	/**
	 * Test get plugin path
	 *
	 * @since 1.1.0
	 */
	public function test_get_plugin_path(): void {
		$path = $this->plugin->get_plugin_path();
		$this->assertIsString( $path );
		$this->assertStringEndsWith( '/', $path );
	}

	/**
	 * Test get plugin URL
	 *
	 * @since 1.1.0
	 */
	public function test_get_plugin_url(): void {
		$url = $this->plugin->get_plugin_url();
		$this->assertIsString( $url );
		$this->assertStringStartsWith( 'http', $url );
	}

	/**
	 * Test get container
	 *
	 * @since 1.1.0
	 */
	public function test_get_container(): void {
		$container = $this->plugin->get_container();
		$this->assertInstanceOf( ContainerInterface::class, $container );
	}

	/**
	 * Test initialization
	 *
	 * @since 1.1.0
	 */
	public function test_initialize(): void {
		// Should not throw any exceptions
		$this->plugin->initialize();
		$this->assertTrue( true );
	}

	/**
	 * Test activation
	 *
	 * @since 1.1.0
	 */
	public function test_activate(): void {
		// Should not throw any exceptions
		$this->plugin->activate();
		$this->assertTrue( true );
	}

	/**
	 * Test deactivation
	 *
	 * @since 1.1.0
	 */
	public function test_deactivate(): void {
		// Should not throw any exceptions
		$this->plugin->deactivate();
		$this->assertTrue( true );
	}

	/**
	 * Test exception on missing parameters
	 *
	 * @since 1.1.0
	 */
	public function test_exception_on_missing_parameters(): void {
		// Reset singleton for this test
		$reflection        = new \ReflectionClass( Plugin::class );
		$instance_property = $reflection->getProperty( 'instance' );
		$instance_property->setAccessible( true );
		$instance_property->setValue( null );

		$this->expectException( PluginException::class );
		Plugin::get_instance(); // No parameters provided
	}
}
