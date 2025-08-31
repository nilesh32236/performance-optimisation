<?php
/**
 * Tests for RecommendationEngine class
 *
 * @package PerformanceOptimisation\Tests\Unit\SiteDetection
 */

namespace PerformanceOptimisation\Tests\Unit\SiteDetection;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Core\SiteDetection\SiteAnalyzer;
use PerformanceOptimisation\Core\SiteDetection\RecommendationEngine;

/**
 * Test case for RecommendationEngine class.
 */
class RecommendationEngineTest extends TestCase {

	/**
	 * RecommendationEngine instance.
	 *
	 * @var RecommendationEngine
	 */
	private RecommendationEngine $engine;

	/**
	 * Mock SiteAnalyzer instance.
	 *
	 * @var SiteAnalyzer|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $mockAnalyzer;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mockAnalyzer = $this->createMock( SiteAnalyzer::class );
		$this->engine       = new RecommendationEngine( $this->mockAnalyzer );
	}

	/**
	 * Test recommended preset for low-risk site.
	 */
	public function test_recommended_preset_for_low_risk_site(): void {
		$mockAnalysis = $this->getMockAnalysisData( 'low_risk' );

		$this->mockAnalyzer
			->expects( $this->once() )
			->method( 'analyze_site' )
			->willReturn( $mockAnalysis );

		$preset = $this->engine->get_recommended_preset();

		$this->assertIsArray( $preset );
		$this->assertArrayHasKey( 'preset', $preset );
		$this->assertArrayHasKey( 'confidence', $preset );
		$this->assertArrayHasKey( 'reasons', $preset );
		$this->assertArrayHasKey( 'adjustments', $preset );

		$this->assertEquals( 'advanced', $preset['preset'] );
		$this->assertGreaterThan( 70, $preset['confidence'] );
	}

	/**
	 * Test recommended preset for high-risk site.
	 */
	public function test_recommended_preset_for_high_risk_site(): void {
		$mockAnalysis = $this->getMockAnalysisData( 'high_risk' );

		$this->mockAnalyzer
			->expects( $this->once() )
			->method( 'analyze_site' )
			->willReturn( $mockAnalysis );

		$preset = $this->engine->get_recommended_preset();

		$this->assertEquals( 'safe', $preset['preset'] );
		$this->assertIsArray( $preset['reasons'] );
		$this->assertNotEmpty( $preset['reasons'] );
	}

	/**
	 * Test recommended preset for medium-risk site.
	 */
	public function test_recommended_preset_for_medium_risk_site(): void {
		$mockAnalysis = $this->getMockAnalysisData( 'medium_risk' );

		$this->mockAnalyzer
			->expects( $this->once() )
			->method( 'analyze_site' )
			->willReturn( $mockAnalysis );

		$preset = $this->engine->get_recommended_preset();

		$this->assertEquals( 'recommended', $preset['preset'] );
	}

	/**
	 * Test personalized recommendations structure.
	 */
	public function test_personalized_recommendations_structure(): void {
		$mockAnalysis = $this->getMockAnalysisData( 'medium_risk' );

		$this->mockAnalyzer
			->expects( $this->once() )
			->method( 'analyze_site' )
			->willReturn( $mockAnalysis );

		$recommendations = $this->engine->get_personalized_recommendations();

		$this->assertIsArray( $recommendations );

		foreach ( $recommendations as $recommendation ) {
			$this->assertArrayHasKey( 'type', $recommendation );
			$this->assertArrayHasKey( 'priority', $recommendation );
			$this->assertArrayHasKey( 'title', $recommendation );
			$this->assertArrayHasKey( 'description', $recommendation );
			$this->assertArrayHasKey( 'action', $recommendation );
			$this->assertArrayHasKey( 'impact', $recommendation );

			$this->assertContains( $recommendation['priority'], array( 'critical', 'high', 'medium', 'low' ) );
		}
	}

	/**
	 * Test caching recommendations.
	 */
	public function test_caching_recommendations(): void {
		$mockAnalysis = $this->getMockAnalysisData( 'low_risk' );

		$this->mockAnalyzer
			->expects( $this->once() )
			->method( 'analyze_site' )
			->willReturn( $mockAnalysis );

		$recommendations = $this->engine->get_feature_recommendations( 'caching' );

		$this->assertIsArray( $recommendations );
		$this->assertArrayHasKey( 'page_caching', $recommendations );
		$this->assertArrayHasKey( 'object_caching', $recommendations );

		foreach ( $recommendations as $feature => $rec ) {
			$this->assertArrayHasKey( 'recommended', $rec );
			$this->assertArrayHasKey( 'confidence', $rec );
			$this->assertArrayHasKey( 'reasons', $rec );
			$this->assertIsBool( $rec['recommended'] );
			$this->assertIsInt( $rec['confidence'] );
			$this->assertIsArray( $rec['reasons'] );
		}
	}

	/**
	 * Test image optimization recommendations.
	 */
	public function test_image_optimization_recommendations(): void {
		$mockAnalysis = $this->getMockAnalysisData( 'high_images' );

		$this->mockAnalyzer
			->expects( $this->once() )
			->method( 'analyze_site' )
			->willReturn( $mockAnalysis );

		$recommendations = $this->engine->get_feature_recommendations( 'image_optimization' );

		$this->assertIsArray( $recommendations );
		$this->assertArrayHasKey( 'webp_conversion', $recommendations );
		$this->assertArrayHasKey( 'lazy_loading', $recommendations );

		// Should recommend WebP for sites with many images
		$this->assertTrue( $recommendations['webp_conversion']['recommended'] );
		$this->assertGreaterThan( 80, $recommendations['webp_conversion']['confidence'] );
	}

	/**
	 * Test minification recommendations with conflicts.
	 */
	public function test_minification_recommendations_with_conflicts(): void {
		$mockAnalysis = $this->getMockAnalysisData( 'minification_conflicts' );

		$this->mockAnalyzer
			->expects( $this->once() )
			->method( 'analyze_site' )
			->willReturn( $mockAnalysis );

		$recommendations = $this->engine->get_feature_recommendations( 'minification' );

		$this->assertIsArray( $recommendations );
		$this->assertArrayHasKey( 'css_minification', $recommendations );
		$this->assertArrayHasKey( 'js_minification', $recommendations );

		// Should not recommend minification when conflicts exist
		$this->assertFalse( $recommendations['css_minification']['recommended'] );
		$this->assertLessThan( 50, $recommendations['css_minification']['confidence'] );
	}

	/**
	 * Test hosting recommendations for low memory.
	 */
	public function test_hosting_recommendations_for_low_memory(): void {
		$mockAnalysis = $this->getMockAnalysisData( 'low_memory' );

		$this->mockAnalyzer
			->expects( $this->once() )
			->method( 'analyze_site' )
			->willReturn( $mockAnalysis );

		$recommendations = $this->engine->get_personalized_recommendations();

		// Should include memory limit recommendation
		$memoryRec = array_filter(
			$recommendations,
			function ( $rec ) {
				return $rec['type'] === 'hosting' && strpos( $rec['title'], 'Memory' ) !== false;
			}
		);

		$this->assertNotEmpty( $memoryRec );

		$memoryRec = array_values( $memoryRec )[0];
		$this->assertEquals( 'high', $memoryRec['priority'] );
	}

	/**
	 * Test e-commerce site detection affects recommendations.
	 */
	public function test_ecommerce_site_recommendations(): void {
		$mockAnalysis = $this->getMockAnalysisData( 'ecommerce' );

		$this->mockAnalyzer
			->expects( $this->once() )
			->method( 'analyze_site' )
			->willReturn( $mockAnalysis );

		$preset = $this->engine->get_recommended_preset();

		// E-commerce sites should get safer recommendations
		$this->assertContains( $preset['preset'], array( 'safe', 'recommended' ) );

		$minificationRecs = $this->engine->get_feature_recommendations( 'minification' );

		// JS minification should be more cautious for e-commerce
		$this->assertLessThan( 80, $minificationRecs['js_minification']['confidence'] );
	}

	/**
	 * Get mock analysis data for different scenarios.
	 *
	 * @param string $scenario Test scenario.
	 * @return array<string, mixed> Mock analysis data.
	 */
	private function getMockAnalysisData( string $scenario ): array {
		$baseData = array(
			'hosting'         => array(
				'server_software'  => 'Apache',
				'php_version'      => '8.1.0',
				'memory_limit'     => 268435456, // 256MB
				'hosting_provider' => 'Unknown',
				'ssl_enabled'      => true,
				'gzip_enabled'     => true,
			),
			'wordpress'       => array(
				'version'       => '6.2.0',
				'multisite'     => false,
				'debug_enabled' => false,
			),
			'plugins'         => array(
				'total_count'         => 10,
				'plugins'             => array(),
				'performance_plugins' => array(),
				'conflicts'           => array(),
			),
			'theme'           => array(
				'name'     => 'Test Theme',
				'supports' => array(),
			),
			'content'         => array(
				'post_count'    => 100,
				'media_count'   => 50,
				'large_images'  => 5,
				'image_formats' => array(
					'jpeg' => 30,
					'png'  => 20,
				),
			),
			'performance'     => array(
				'optimization_score' => 75,
				'memory_usage'       => array(
					'current' => 134217728, // 128MB
					'limit'   => 268435456,   // 256MB
				),
			),
			'compatibility'   => array(
				'page_caching'       => array(
					'compatible' => true,
					'score'      => 100,
				),
				'object_caching'     => array(
					'compatible' => true,
					'score'      => 80,
				),
				'image_optimization' => array(
					'compatible' => true,
					'score'      => 90,
				),
				'minification'       => array(
					'compatible' => true,
					'score'      => 85,
				),
			),
			'conflicts'       => array(),
			'recommendations' => array(),
		);

		switch ( $scenario ) {
			case 'high_risk':
				$baseData['conflicts']                      = array(
					array(
						'type'     => 'caching',
						'severity' => 'high',
					),
				);
				$baseData['plugins']['performance_plugins'] = array( 'wp-rocket', 'w3-total-cache' );
				$baseData['plugins']['conflicts']           = array(
					array(
						'plugin'   => 'wp-rocket',
						'severity' => 'high',
					),
				);
				break;

			case 'medium_risk':
				$baseData['hosting']['memory_limit']        = 134217728; // 128MB
				$baseData['plugins']['performance_plugins'] = array( 'wp-rocket' );
				break;

			case 'low_risk':
				$baseData['hosting']['hosting_provider'] = 'kinsta';
				$baseData['hosting']['memory_limit']     = 536870912; // 512MB
				break;

			case 'high_images':
				$baseData['content']['media_count']   = 500;
				$baseData['content']['large_images']  = 50;
				$baseData['content']['image_formats'] = array(
					'jpeg' => 300,
					'png'  => 200,
				);
				break;

			case 'minification_conflicts':
				$baseData['conflicts']                      = array(
					array(
						'type'     => 'minification',
						'severity' => 'medium',
					),
				);
				$baseData['plugins']['performance_plugins'] = array( 'autoptimize' );
				break;

			case 'low_memory':
				$baseData['hosting']['memory_limit'] = 134217728; // 128MB
				break;

			case 'ecommerce':
				$baseData['plugins']['plugins']['woocommerce'] = array(
					'name'    => 'WooCommerce',
					'version' => '7.0.0',
				);
				break;
		}

		return $baseData;
	}
}
