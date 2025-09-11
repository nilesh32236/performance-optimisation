<?php
/**
 * Recommendation Engine Class
 *
 * Generates intelligent recommendations based on site analysis
 * and provides preset configurations tailored to specific site characteristics.
 *
 * @package PerformanceOptimisation\Core\SiteDetection
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\SiteDetection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recommendation Engine class for generating optimization recommendations.
 */
class RecommendationEngine {

	/**
	 * Site analyzer instance.
	 *
	 * @var SiteAnalyzer
	 */
	private SiteAnalyzer $analyzer;

	/**
	 * Constructor.
	 *
	 * @param SiteAnalyzer $analyzer Site analyzer instance.
	 */
	public function __construct( SiteAnalyzer $analyzer ) {
		$this->analyzer = $analyzer;
	}

	/**
	 * Get recommended preset based on site analysis.
	 *
	 * @return array<string, mixed> Recommended preset configuration.
	 */
	public function get_recommended_preset(): array {
		$analysis = $this->analyzer->analyze_site();

		// Calculate risk score based on various factors
		$risk_score = $this->calculate_risk_score( $analysis );

		// Determine appropriate preset
		if ( $risk_score >= 80 ) {
			$preset = 'safe';
		} elseif ( $risk_score >= 50 ) {
			$preset = 'recommended';
		} else {
			$preset = 'advanced';
		}

		return array(
			'preset'      => $preset,
			'confidence'  => $this->calculate_confidence( $analysis, $preset ),
			'reasons'     => $this->get_preset_reasons( $analysis, $preset ),
			'adjustments' => $this->get_preset_adjustments( $analysis, $preset ),
		);
	}

	/**
	 * Get personalized recommendations based on site characteristics.
	 *
	 * @return array<string, mixed> Personalized recommendations.
	 */
	public function get_personalized_recommendations(): array {
		$analysis        = $this->analyzer->analyze_site();
		$recommendations = array();

		// Hosting-based recommendations
		$recommendations = array_merge(
			$recommendations,
			$this->get_hosting_recommendations( $analysis['hosting'] )
		);

		// Content-based recommendations
		$recommendations = array_merge(
			$recommendations,
			$this->get_content_recommendations( $analysis['content'] )
		);

		// Plugin-based recommendations
		$recommendations = array_merge(
			$recommendations,
			$this->get_plugin_recommendations( $analysis['plugins'] )
		);

		// Performance-based recommendations
		$recommendations = array_merge(
			$recommendations,
			$this->get_performance_recommendations( $analysis['performance'] )
		);

		// Sort by priority
		usort(
			$recommendations,
			function ( $a, $b ) {
				$priority_order = array(
					'critical' => 0,
					'high'     => 1,
					'medium'   => 2,
					'low'      => 3,
				);
				return $priority_order[ $a['priority'] ] <=> $priority_order[ $b['priority'] ];
			}
		);

		return $recommendations;
	}

	/**
	 * Get feature-specific recommendations.
	 *
	 * @param string $feature Feature name.
	 * @return array<string, mixed> Feature recommendations.
	 */
	public function get_feature_recommendations( string $feature ): array {
		$analysis = $this->analyzer->analyze_site();

		switch ( $feature ) {
			case 'caching':
				return $this->get_caching_recommendations( $analysis );
			case 'image_optimization':
				return $this->get_image_optimization_recommendations( $analysis );
			case 'minification':
				return $this->get_minification_recommendations( $analysis );
			case 'lazy_loading':
				return $this->get_lazy_loading_recommendations( $analysis );
			case 'critical_css':
				return $this->get_critical_css_recommendations( $analysis );
			default:
				return array();
		}
	}

	/**
	 * Calculate risk score for the site.
	 *
	 * @param array<string, mixed> $analysis Site analysis data.
	 * @return int Risk score (0-100, higher = more risky).
	 */
	private function calculate_risk_score( array $analysis ): int {
		$risk_score = 0;

		// Plugin conflicts increase risk
		if ( ! empty( $analysis['conflicts'] ) ) {
			$risk_score += count( $analysis['conflicts'] ) * 15;
		}

		// Multiple performance plugins increase risk
		if ( count( $analysis['plugins']['performance_plugins'] ) > 1 ) {
			$risk_score += 20;
		}

		// Older WordPress version increases risk
		if ( version_compare( $analysis['wordpress']['version'], '6.0', '<' ) ) {
			$risk_score += 15;
		}

		// Low memory limit increases risk
		if ( $analysis['hosting']['memory_limit'] > 0 && $analysis['hosting']['memory_limit'] < 268435456 ) { // 256MB
			$risk_score += 10;
		}

		// Unknown hosting provider increases risk slightly
		if ( $analysis['hosting']['hosting_provider'] === 'Unknown' ) {
			$risk_score += 5;
		}

		// E-commerce sites are more sensitive
		if ( $this->is_ecommerce_site( $analysis ) ) {
			$risk_score += 15;
		}

		// High traffic sites need more careful optimization
		if ( $this->is_high_traffic_site( $analysis ) ) {
			$risk_score += 10;
		}

		return min( 100, max( 0, $risk_score ) );
	}

	/**
	 * Calculate confidence level for preset recommendation.
	 *
	 * @param array<string, mixed> $analysis Site analysis data.
	 * @param string               $preset Recommended preset.
	 * @return int Confidence level (0-100).
	 */
	private function calculate_confidence( array $analysis, string $preset ): int {
		$confidence = 70; // Base confidence

		// Increase confidence if we have good hosting info
		if ( $analysis['hosting']['hosting_provider'] !== 'Unknown' ) {
			$confidence += 10;
		}

		// Increase confidence if no conflicts detected
		if ( empty( $analysis['conflicts'] ) ) {
			$confidence += 15;
		}

		// Increase confidence for known good hosting providers
		$good_hosts = array( 'wpengine', 'kinsta', 'siteground' );
		if ( in_array( $analysis['hosting']['hosting_provider'], $good_hosts, true ) ) {
			$confidence += 5;
		}

		return min( 100, $confidence );
	}

	/**
	 * Get reasons for preset recommendation.
	 *
	 * @param array<string, mixed> $analysis Site analysis data.
	 * @param string               $preset Recommended preset.
	 * @return array<string> Reasons for recommendation.
	 */
	private function get_preset_reasons( array $analysis, string $preset ): array {
		$reasons = array();

		switch ( $preset ) {
			case 'safe':
				if ( ! empty( $analysis['conflicts'] ) ) {
					$reasons[] = 'Conflicts detected with existing plugins';
				}
				if ( count( $analysis['plugins']['performance_plugins'] ) > 1 ) {
					$reasons[] = 'Multiple performance plugins are active';
				}
				if ( $this->is_ecommerce_site( $analysis ) ) {
					$reasons[] = 'E-commerce site detected - prioritizing stability';
				}
				break;

			case 'recommended':
				$reasons[] = 'Good balance of performance and compatibility';
				if ( $analysis['hosting']['memory_limit'] >= 268435456 ) {
					$reasons[] = 'Sufficient server resources available';
				}
				break;

			case 'advanced':
				$reasons[] = 'Site appears well-optimized for aggressive settings';
				if ( empty( $analysis['conflicts'] ) ) {
					$reasons[] = 'No conflicts detected with existing setup';
				}
				if ( $analysis['hosting']['hosting_provider'] !== 'Unknown' ) {
					$reasons[] = 'Reliable hosting environment detected';
				}
				break;
		}

		return $reasons;
	}

	/**
	 * Get preset-specific adjustments.
	 *
	 * @param array<string, mixed> $analysis Site analysis data.
	 * @param string               $preset Recommended preset.
	 * @return array<string, mixed> Preset adjustments.
	 */
	private function get_preset_adjustments( array $analysis, string $preset ): array {
		$adjustments = array();

		// Disable features that have conflicts
		foreach ( $analysis['conflicts'] as $conflict ) {
			switch ( $conflict['type'] ) {
				case 'caching':
					$adjustments['disable_page_caching'] = true;
					break;
				case 'minification':
					$adjustments['disable_minification'] = true;
					break;
			}
		}

		// Enable features based on compatibility
		if ( $analysis['compatibility']['image_optimization']['score'] >= 80 ) {
			$adjustments['enable_image_optimization'] = true;
		}

		if ( $analysis['compatibility']['object_caching']['score'] >= 80 ) {
			$adjustments['enable_object_caching'] = true;
		}

		return $adjustments;
	}

	/**
	 * Get hosting-based recommendations.
	 *
	 * @param array<string, mixed> $hosting Hosting analysis data.
	 * @return array<string, mixed> Hosting recommendations.
	 */
	private function get_hosting_recommendations( array $hosting ): array {
		$recommendations = array();

		// Memory limit recommendations
		if ( $hosting['memory_limit'] > 0 && $hosting['memory_limit'] < 268435456 ) {
			$recommendations[] = array(
				'type'        => 'hosting',
				'priority'    => 'high',
				'title'       => 'Increase PHP Memory Limit',
				'description' => sprintf(
					'Your current memory limit is %s. We recommend at least 256MB for optimal performance.',
					size_format( $hosting['memory_limit'] )
				),
				'action'      => 'contact_hosting_provider',
				'impact'      => 'high',
			);
		}

		// PHP version recommendations
		if ( version_compare( $hosting['php_version'], '8.0', '<' ) ) {
			$recommendations[] = array(
				'type'        => 'hosting',
				'priority'    => 'medium',
				'title'       => 'Upgrade PHP Version',
				'description' => sprintf(
					'You are using PHP %s. Upgrading to PHP 8.0+ can improve performance by 20-30%%.',
					$hosting['php_version']
				),
				'action'      => 'upgrade_php',
				'impact'      => 'medium',
			);
		}

		// GZIP recommendations
		if ( ! $hosting['gzip_enabled'] ) {
			$recommendations[] = array(
				'type'        => 'hosting',
				'priority'    => 'medium',
				'title'       => 'Enable GZIP Compression',
				'description' => 'GZIP compression can reduce file sizes by 70-90%.',
				'action'      => 'enable_gzip',
				'impact'      => 'medium',
			);
		}

		return $recommendations;
	}

	/**
	 * Get content-based recommendations.
	 *
	 * @param array<string, mixed> $content Content analysis data.
	 * @return array<string, mixed> Content recommendations.
	 */
	private function get_content_recommendations( array $content ): array {
		$recommendations = array();

		// Large images recommendation
		if ( $content['large_images'] > 10 ) {
			$recommendations[] = array(
				'type'        => 'content',
				'priority'    => 'high',
				'title'       => 'Optimize Large Images',
				'description' => sprintf(
					'You have %d large images that could benefit from optimization.',
					$content['large_images']
				),
				'action'      => 'enable_image_optimization',
				'impact'      => 'high',
			);
		}

		// Image format recommendations
		if ( isset( $content['image_formats']['jpeg'] ) &&
			$content['image_formats']['jpeg'] > 50 ) {
			$recommendations[] = array(
				'type'        => 'content',
				'priority'    => 'medium',
				'title'       => 'Convert Images to Modern Formats',
				'description' => 'Converting JPEG images to WebP can reduce file sizes by 25-50%.',
				'action'      => 'enable_webp_conversion',
				'impact'      => 'medium',
			);
		}

		// High post count recommendations
		if ( $content['post_count'] > 1000 ) {
			$recommendations[] = array(
				'type'        => 'content',
				'priority'    => 'medium',
				'title'       => 'Enable Object Caching',
				'description' => 'With many posts, object caching can significantly improve database performance.',
				'action'      => 'enable_object_caching',
				'impact'      => 'medium',
			);
		}

		return $recommendations;
	}

	/**
	 * Get plugin-based recommendations.
	 *
	 * @param array<string, mixed> $plugins Plugin analysis data.
	 * @return array<string, mixed> Plugin recommendations.
	 */
	private function get_plugin_recommendations( array $plugins ): array {
		$recommendations = array();

		// Multiple performance plugins
		if ( count( $plugins['performance_plugins'] ) > 1 ) {
			$recommendations[] = array(
				'type'        => 'plugins',
				'priority'    => 'high',
				'title'       => 'Review Performance Plugins',
				'description' => 'Multiple performance plugins can cause conflicts. Consider using only one.',
				'action'      => 'review_performance_plugins',
				'impact'      => 'high',
			);
		}

		// Plugin conflicts
		if ( ! empty( $plugins['conflicts'] ) ) {
			foreach ( $plugins['conflicts'] as $conflict ) {
				$recommendations[] = array(
					'type'        => 'plugins',
					'priority'    => $conflict['severity'] === 'high' ? 'high' : 'medium',
					'title'       => 'Plugin Conflict Detected',
					'description' => sprintf(
						'Conflict detected with %s plugin.',
						$conflict['plugin']
					),
					'action'      => 'resolve_plugin_conflict',
					'impact'      => $conflict['severity'],
				);
			}
		}

		return $recommendations;
	}

	/**
	 * Get performance-based recommendations.
	 *
	 * @param array<string, mixed> $performance Performance analysis data.
	 * @return array<string, mixed> Performance recommendations.
	 */
	private function get_performance_recommendations( array $performance ): array {
		$recommendations = array();

		// Low optimization score
		if ( $performance['optimization_score'] < 70 ) {
			$recommendations[] = array(
				'type'        => 'performance',
				'priority'    => 'high',
				'title'       => 'Enable Basic Optimizations',
				'description' => 'Your site would benefit from basic performance optimizations like caching.',
				'action'      => 'enable_basic_optimizations',
				'impact'      => 'high',
			);
		}

		// High memory usage
		if ( $performance['memory_usage']['current'] > ( $performance['memory_usage']['limit'] * 0.8 ) ) {
			$recommendations[] = array(
				'type'        => 'performance',
				'priority'    => 'medium',
				'title'       => 'High Memory Usage Detected',
				'description' => 'Your site is using a high amount of memory. Consider optimization.',
				'action'      => 'optimize_memory_usage',
				'impact'      => 'medium',
			);
		}

		return $recommendations;
	}

	/**
	 * Get caching-specific recommendations.
	 *
	 * @param array<string, mixed> $analysis Site analysis data.
	 * @return array<string, mixed> Caching recommendations.
	 */
	private function get_caching_recommendations( array $analysis ): array {
		$recommendations = array(
			'page_caching'   => array(
				'recommended' => true,
				'confidence'  => 90,
				'reasons'     => array( 'Significant performance improvement for all sites' ),
			),
			'object_caching' => array(
				'recommended' => $analysis['content']['post_count'] > 100,
				'confidence'  => $analysis['content']['post_count'] > 100 ? 85 : 60,
				'reasons'     => $analysis['content']['post_count'] > 100
					? array( 'High content volume benefits from object caching' )
					: array( 'May provide modest benefits for smaller sites' ),
			),
		);

		// Adjust based on hosting
		if ( $analysis['hosting']['hosting_provider'] === 'wpengine' ) {
			$recommendations['page_caching']['recommended'] = false;
			$recommendations['page_caching']['reasons']     = array( 'WP Engine provides built-in caching' );
		}

		return $recommendations;
	}

	/**
	 * Get image optimization recommendations.
	 *
	 * @param array<string, mixed> $analysis Site analysis data.
	 * @return array<string, mixed> Image optimization recommendations.
	 */
	private function get_image_optimization_recommendations( array $analysis ): array {
		$has_many_images  = $analysis['content']['media_count'] > 50;
		$has_large_images = $analysis['content']['large_images'] > 5;

		return array(
			'webp_conversion' => array(
				'recommended' => $has_many_images || $has_large_images,
				'confidence'  => $has_many_images ? 90 : 70,
				'reasons'     => $has_many_images
					? array( 'Many images detected - WebP can significantly reduce file sizes' )
					: array( 'WebP conversion provides good compression benefits' ),
			),
			'lazy_loading'    => array(
				'recommended' => true,
				'confidence'  => 95,
				'reasons'     => array( 'Improves initial page load time for all sites' ),
			),
		);
	}

	/**
	 * Get minification recommendations.
	 *
	 * @param array<string, mixed> $analysis Site analysis data.
	 * @return array<string, mixed> Minification recommendations.
	 */
	private function get_minification_recommendations( array $analysis ): array {
		$has_conflicts = ! empty(
			array_filter(
				$analysis['conflicts'],
				function ( $conflict ) {
					return $conflict['type'] === 'minification';
				}
			)
		);

		return array(
			'css_minification' => array(
				'recommended' => ! $has_conflicts,
				'confidence'  => $has_conflicts ? 30 : 85,
				'reasons'     => $has_conflicts
					? array( 'Conflicts detected with existing minification plugins' )
					: array( 'CSS minification reduces file sizes and improves load times' ),
			),
			'js_minification'  => array(
				'recommended' => ! $has_conflicts && ! $this->is_ecommerce_site( $analysis ),
				'confidence'  => $has_conflicts ? 30 : ( $this->is_ecommerce_site( $analysis ) ? 60 : 80 ),
				'reasons'     => $has_conflicts
					? array( 'Conflicts detected with existing minification plugins' )
					: ( $this->is_ecommerce_site( $analysis )
						? array( 'E-commerce sites may be sensitive to JS changes' )
						: array( 'JavaScript minification reduces file sizes' ) ),
			),
		);
	}

	/**
	 * Get lazy loading recommendations.
	 *
	 * @param array<string, mixed> $analysis Site analysis data.
	 * @return array<string, mixed> Lazy loading recommendations.
	 */
	private function get_lazy_loading_recommendations( array $analysis ): array {
		return array(
			'images'  => array(
				'recommended' => true,
				'confidence'  => 95,
				'reasons'     => array( 'Improves initial page load time' ),
			),
			'iframes' => array(
				'recommended' => true,
				'confidence'  => 90,
				'reasons'     => array( 'Reduces initial page weight' ),
			),
		);
	}

	/**
	 * Get critical CSS recommendations.
	 *
	 * @param array<string, mixed> $analysis Site analysis data.
	 * @return array<string, mixed> Critical CSS recommendations.
	 */
	private function get_critical_css_recommendations( array $analysis ): array {
		$is_complex_theme = ! empty( $analysis['theme']['supports'] );

		return array(
			'enable' => array(
				'recommended' => ! $is_complex_theme,
				'confidence'  => $is_complex_theme ? 60 : 80,
				'reasons'     => $is_complex_theme
					? array( 'Complex themes may have issues with critical CSS' )
					: array( 'Critical CSS eliminates render-blocking CSS' ),
			),
		);
	}

	/**
	 * Check if site appears to be an e-commerce site.
	 *
	 * @param array<string, mixed> $analysis Site analysis data.
	 * @return bool True if e-commerce site detected.
	 */
	private function is_ecommerce_site( array $analysis ): bool {
		$ecommerce_plugins = array( 'woocommerce', 'easy-digital-downloads', 'wp-ecommerce' );

		foreach ( $ecommerce_plugins as $plugin ) {
			if ( isset( $analysis['plugins']['plugins'][ $plugin ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if site appears to be high traffic.
	 *
	 * @param array<string, mixed> $analysis Site analysis data.
	 * @return bool True if high traffic site detected.
	 */
	private function is_high_traffic_site( array $analysis ): bool {
		// This is a simplified check - in reality you'd want to analyze actual traffic data
		return $analysis['content']['post_count'] > 1000 ||
				$analysis['content']['comment_count'] > 5000;
	}
}
