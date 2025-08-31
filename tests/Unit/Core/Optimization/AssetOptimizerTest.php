<?php
/**
 * AssetOptimizer Unit Tests
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Tests\Unit\Core\Optimization;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Core\Optimization\AssetOptimizer;
use PerformanceOptimisation\Core\Config\ConfigManager;
use PerformanceOptimisation\Interfaces\OptimizerInterface;
use PerformanceOptimisation\Exceptions\OptimizationException;

/**
 * Test cases for the AssetOptimizer class
 *
 * @since 1.1.0
 */
class AssetOptimizerTest extends TestCase {

	/**
	 * AssetOptimizer instance
	 *
	 * @var AssetOptimizer
	 */
	private AssetOptimizer $asset_optimizer;

	/**
	 * Mock config manager
	 *
	 * @var ConfigManager
	 */
	private ConfigManager $config;

	/**
	 * Set up test environment
	 *
	 * @since 1.1.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->config          = new ConfigManager();
		$this->asset_optimizer = new AssetOptimizer( $this->config );
	}

	/**
	 * Test registering an optimizer
	 *
	 * @since 1.1.0
	 */
	public function test_register_optimizer(): void {
		$mock_optimizer = $this->createMock( OptimizerInterface::class );
		$mock_optimizer->method( 'get_name' )->willReturn( 'test' );

		$this->asset_optimizer->register_optimizer( $mock_optimizer );

		$optimizer = $this->asset_optimizer->get_optimizer( 'test' );
		$this->assertSame( $mock_optimizer, $optimizer );
	}

	/**
	 * Test getting non-existent optimizer throws exception
	 *
	 * @since 1.1.0
	 */
	public function test_get_non_existent_optimizer_throws_exception(): void {
		$this->expectException( OptimizationException::class );
		$this->asset_optimizer->get_optimizer( 'non_existent' );
	}

	/**
	 * Test CSS optimization
	 *
	 * @since 1.1.0
	 */
	public function test_optimize_css(): void {
		$css       = 'body { color: red; }';
		$optimized = $this->asset_optimizer->optimize_css( $css );

		$this->assertIsString( $optimized );
		$this->assertNotEmpty( $optimized );
	}

	/**
	 * Test JavaScript optimization
	 *
	 * @since 1.1.0
	 */
	public function test_optimize_js(): void {
		$js        = 'function test() { console.log("hello"); }';
		$optimized = $this->asset_optimizer->optimize_js( $js );

		$this->assertIsString( $optimized );
		$this->assertNotEmpty( $optimized );
	}

	/**
	 * Test HTML optimization
	 *
	 * @since 1.1.0
	 */
	public function test_optimize_html(): void {
		$html      = '<html><body><p>Hello World</p></body></html>';
		$optimized = $this->asset_optimizer->optimize_html( $html );

		$this->assertIsString( $optimized );
		$this->assertNotEmpty( $optimized );
	}

	/**
	 * Test CSS combination
	 *
	 * @since 1.1.0
	 */
	public function test_combine_css(): void {
		$css_files = array(
			'body { color: red; }',
			'h1 { font-size: 24px; }',
		);

		// Enable CSS combination in config
		$this->config->set( 'minification.combine_css', true );

		$combined = $this->asset_optimizer->combine_css( $css_files );
		$this->assertIsString( $combined );
	}

	/**
	 * Test JavaScript combination
	 *
	 * @since 1.1.0
	 */
	public function test_combine_js(): void {
		$js_files = array(
			'function test1() { return 1; }',
			'function test2() { return 2; }',
		);

		// Enable JS combination in config
		$this->config->set( 'minification.combine_js', true );

		$combined = $this->asset_optimizer->combine_js( $js_files );
		$this->assertIsString( $combined );
	}

	/**
	 * Test optimization statistics
	 *
	 * @since 1.1.0
	 */
	public function test_get_stats(): void {
		$stats = $this->asset_optimizer->get_stats();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'global', $stats );
		$this->assertArrayHasKey( 'optimizers', $stats );
	}

	/**
	 * Test reset statistics
	 *
	 * @since 1.1.0
	 */
	public function test_reset_stats(): void {
		// Perform some optimization to generate stats
		$this->asset_optimizer->optimize_css( 'body { color: red; }' );

		// Reset stats
		$this->asset_optimizer->reset_stats();

		$stats = $this->asset_optimizer->get_stats();
		$this->assertEquals( 0, $stats['global']['files_processed'] );
	}

	/**
	 * Test getting available optimizers
	 *
	 * @since 1.1.0
	 */
	public function test_get_available_optimizers(): void {
		$optimizers = $this->asset_optimizer->get_available_optimizers();

		$this->assertIsArray( $optimizers );
		$this->assertContains( 'css', $optimizers );
		$this->assertContains( 'js', $optimizers );
		$this->assertContains( 'html', $optimizers );
	}

	/**
	 * Test optimization enabled check
	 *
	 * @since 1.1.0
	 */
	public function test_is_optimization_enabled(): void {
		$this->assertIsBool( $this->asset_optimizer->is_optimization_enabled( 'css' ) );
		$this->assertIsBool( $this->asset_optimizer->is_optimization_enabled( 'js' ) );
		$this->assertIsBool( $this->asset_optimizer->is_optimization_enabled( 'html' ) );
	}
}
