<?php
/**
 * Preset Validator Class
 *
 * Provides comprehensive validation for preset configurations,
 * including compatibility checks and performance impact analysis.
 *
 * @package PerformanceOptimisation\Core\Presets
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Presets;

use PerformanceOptimisation\Core\SiteDetection\SiteAnalyzer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preset Validator class for validating preset configurations.
 */
class PresetValidator {

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
	 * Validate preset against current site configuration.
	 *
	 * @param array<string, mixed> $preset_config Preset configuration.
	 * @return array<string, mixed> Validation result.
	 */
	public function validate_preset_for_site( array $preset_config ): array {
		$site_analysis = $this->analyzer->analyze_site();

		$validation_result = array(
			'compatible'      => true,
			'score'           => 100,
			'issues'          => array(),
			'warnings'        => array(),
			'recommendations' => array(),
		);

		// Validate each setting group
		if ( isset( $preset_config['settings'] ) ) {
			$settings = $preset_config['settings'];

			$validation_result = $this->validate_cache_settings( $settings, $site_analysis, $validation_result );
			$validation_result = $this->validate_image_settings( $settings, $site_analysis, $validation_result );
			$validation_result = $this->validate_file_optimization_settings( $settings, $site_analysis, $validation_result );
			$validation_result = $this->validate_preload_settings( $settings, $site_analysis, $validation_result );
		}

		// Calculate final compatibility
		$validation_result['compatible'] = empty( $validation_result['issues'] );

		return $validation_result;
	}

	/**
	 * Test preset configuration safely.
	 *
	 * @param array<string, mixed> $preset_config Preset configuration.
	 * @return array<string, mixed> Test result.
	 */
	public function test_preset( array $preset_config ): array {
		$test_result = array(
			'success'               => true,
			'performance_impact'    => array(),
			'compatibility_issues'  => array(),
			'estimated_improvement' => 0,
		);

		// Simulate preset application
		$current_settings = get_option( 'wppo_settings', array() );
		$test_settings    = $preset_config['settings'] ?? array();

		// Analyze performance impact
		$test_result['performance_impact'] = $this->analyze_performance_impact( $current_settings, $test_settings );

		// Check compatibility
		$compatibility                       = $this->validate_preset_for_site( $preset_config );
		$test_result['compatibility_issues'] = $compatibility['issues'];

		// Estimate improvement
		$test_result['estimated_improvement'] = $this->estimate_performance_improvement( $test_settings );

		$test_result['success'] = empty( $compatibility['issues'] );

		return $test_result;
	}

	/**
	 * Validate cache settings.
	 *
	 * @param array<string, mixed> $settings Preset settings.
	 * @param array<string, mixed> $site_analysis Site analysis data.
	 * @param array<string, mixed> $validation_result Current validation result.
	 * @return array<string, mixed> Updated validation result.
	 */
	private function validate_cache_settings( array $settings, array $site_analysis, array $validation_result ): array {
		if ( ! isset( $settings['cache_settings'] ) ) {
			return $validation_result;
		}

		$cache_settings = $settings['cache_settings'];

		// Check page caching compatibility
		if ( $cache_settings['enablePageCaching'] ?? false ) {
			// Check for conflicting caching plugins
			$caching_plugins = array_intersect(
				$site_analysis['plugins']['performance_plugins'] ?? array(),
				array( 'wp-rocket', 'w3-total-cache', 'wp-super-cache', 'litespeed-cache' )
			);

			if ( ! empty( $caching_plugins ) ) {
				$validation_result['issues'][] = array(
					'type'     => 'conflict',
					'severity' => 'high',
					'message'  => 'Conflicting caching plugins detected: ' . implode( ', ', $caching_plugins ),
					'setting'  => 'cache_settings.enablePageCaching',
				);
				$validation_result['score']   -= 30;
			}

			// Check hosting provider compatibility
			$hosting_provider = $site_analysis['hosting']['hosting_provider'] ?? 'Unknown';
			if ( $hosting_provider === 'wpengine' ) {
				$validation_result['warnings'][] = array(
					'type'    => 'hosting',
					'message' => 'WP Engine provides built-in caching. Additional page caching may not be necessary.',
					'setting' => 'cache_settings.enablePageCaching',
				);
				$validation_result['score']     -= 10;
			}
		}

		// Check object caching
		if ( $cache_settings['enableObjectCaching'] ?? false ) {
			$object_cache_available = $site_analysis['compatibility']['object_caching']['compatible'] ?? false;
			if ( ! $object_cache_available ) {
				$validation_result['issues'][] = array(
					'type'     => 'requirement',
					'severity' => 'medium',
					'message'  => 'Object caching requires Redis or Memcached extension',
					'setting'  => 'cache_settings.enableObjectCaching',
				);
				$validation_result['score']   -= 20;
			}
		}

		// Validate cache expiration
		$cache_expiration = $cache_settings['cacheExpiration'] ?? 3600;
		if ( $cache_expiration < 300 ) {
			$validation_result['warnings'][] = array(
				'type'    => 'performance',
				'message' => 'Very short cache expiration may reduce performance benefits',
				'setting' => 'cache_settings.cacheExpiration',
			);
			$validation_result['score']     -= 5;
		}

		return $validation_result;
	}

	/**
	 * Validate image optimization settings.
	 *
	 * @param array<string, mixed> $settings Preset settings.
	 * @param array<string, mixed> $site_analysis Site analysis data.
	 * @param array<string, mixed> $validation_result Current validation result.
	 * @return array<string, mixed> Updated validation result.
	 */
	private function validate_image_settings( array $settings, array $site_analysis, array $validation_result ): array {
		if ( ! isset( $settings['image_optimisation'] ) ) {
			return $validation_result;
		}

		$image_settings = $settings['image_optimisation'];

		// Check image conversion compatibility
		if ( $image_settings['convertImg'] ?? false ) {
			$image_compat = $site_analysis['compatibility']['image_optimization'] ?? array();
			if ( ! ( $image_compat['compatible'] ?? false ) ) {
				$validation_result['issues'][] = array(
					'type'     => 'requirement',
					'severity' => 'medium',
					'message'  => 'Image conversion requires GD or ImageMagick extension',
					'setting'  => 'image_optimisation.convertImg',
				);
				$validation_result['score']   -= 15;
			}

			// Check if site has many images
			$media_count = $site_analysis['content']['media_count'] ?? 0;
			if ( $media_count < 10 ) {
				$validation_result['warnings'][] = array(
					'type'    => 'optimization',
					'message' => 'Site has few images. Image conversion may provide minimal benefits.',
					'setting' => 'image_optimisation.convertImg',
				);
			}
		}

		// Validate image quality
		$quality = $image_settings['quality'] ?? 85;
		if ( $quality < 50 ) {
			$validation_result['warnings'][] = array(
				'type'    => 'quality',
				'message' => 'Very low image quality may affect visual appearance',
				'setting' => 'image_optimisation.quality',
			);
		} elseif ( $quality > 95 ) {
			$validation_result['warnings'][] = array(
				'type'    => 'performance',
				'message' => 'Very high image quality may reduce compression benefits',
				'setting' => 'image_optimisation.quality',
			);
		}

		return $validation_result;
	}

	/**
	 * Validate file optimization settings.
	 *
	 * @param array<string, mixed> $settings Preset settings.
	 * @param array<string, mixed> $site_analysis Site analysis data.
	 * @param array<string, mixed> $validation_result Current validation result.
	 * @return array<string, mixed> Updated validation result.
	 */
	private function validate_file_optimization_settings( array $settings, array $site_analysis, array $validation_result ): array {
		if ( ! isset( $settings['file_optimisation'] ) ) {
			return $validation_result;
		}

		$file_settings = $settings['file_optimisation'];

		// Check for minification conflicts
		$minification_plugins = array_intersect(
			$site_analysis['plugins']['performance_plugins'] ?? array(),
			array( 'autoptimize', 'wp-minify', 'fast-velocity-minify' )
		);

		if ( ! empty( $minification_plugins ) ) {
			if ( $file_settings['minifyCSS'] ?? false || $file_settings['minifyJS'] ?? false ) {
				$validation_result['warnings'][] = array(
					'type'    => 'conflict',
					'message' => 'Minification plugins detected: ' . implode( ', ', $minification_plugins ),
					'setting' => 'file_optimisation.minify*',
				);
				$validation_result['score']     -= 10;
			}
		}

		// Check JavaScript optimization for e-commerce sites
		if ( $this->is_ecommerce_site( $site_analysis ) ) {
			if ( $file_settings['minifyJS'] ?? false || $file_settings['deferJS'] ?? false || $file_settings['delayJS'] ?? false ) {
				$validation_result['warnings'][] = array(
					'type'    => 'ecommerce',
					'message' => 'E-commerce sites may be sensitive to JavaScript optimization. Test thoroughly.',
					'setting' => 'file_optimisation.js*',
				);
				$validation_result['score']     -= 5;
			}
		}

		// Check aggressive JS settings
		if ( ( $file_settings['deferJS'] ?? false ) && ( $file_settings['delayJS'] ?? false ) ) {
			$validation_result['warnings'][] = array(
				'type'    => 'aggressive',
				'message' => 'Using both defer and delay JS may cause functionality issues',
				'setting' => 'file_optimisation.deferJS, file_optimisation.delayJS',
			);
			$validation_result['score']     -= 10;
		}

		// Check critical CSS requirements
		if ( $file_settings['criticalCSS'] ?? false ) {
			if ( ! extension_loaded( 'curl' ) ) {
				$validation_result['issues'][] = array(
					'type'     => 'requirement',
					'severity' => 'medium',
					'message'  => 'Critical CSS generation requires CURL extension',
					'setting'  => 'file_optimisation.criticalCSS',
				);
				$validation_result['score']   -= 15;
			}
		}

		return $validation_result;
	}

	/**
	 * Validate preload settings.
	 *
	 * @param array<string, mixed> $settings Preset settings.
	 * @param array<string, mixed> $site_analysis Site analysis data.
	 * @param array<string, mixed> $validation_result Current validation result.
	 * @return array<string, mixed> Updated validation result.
	 */
	private function validate_preload_settings( array $settings, array $site_analysis, array $validation_result ): array {
		if ( ! isset( $settings['preload_settings'] ) ) {
			return $validation_result;
		}

		$preload_settings = $settings['preload_settings'];

		// Check cron job availability
		if ( $preload_settings['enableCronJobs'] ?? false ) {
			$cron_enabled = $site_analysis['wordpress']['cron_enabled'] ?? true;
			if ( ! $cron_enabled ) {
				$validation_result['issues'][] = array(
					'type'     => 'requirement',
					'severity' => 'medium',
					'message'  => 'WordPress cron is disabled. Preload functionality may not work properly.',
					'setting'  => 'preload_settings.enableCronJobs',
				);
				$validation_result['score']   -= 20;
			}
		}

		// Check preload page count
		$preload_pages = $preload_settings['preloadPages'] ?? 0;
		$post_count    = $site_analysis['content']['post_count'] ?? 0;

		if ( $preload_pages > $post_count * 0.5 ) {
			$validation_result['warnings'][] = array(
				'type'    => 'performance',
				'message' => 'Preloading many pages may impact server performance',
				'setting' => 'preload_settings.preloadPages',
			);
		}

		// Check server resources for preloading
		$memory_limit = $site_analysis['hosting']['memory_limit'] ?? 0;
		if ( $memory_limit > 0 && $memory_limit < 268435456 && $preload_pages > 10 ) { // 256MB
			$validation_result['warnings'][] = array(
				'type'    => 'resource',
				'message' => 'Low memory limit may affect preload performance with many pages',
				'setting' => 'preload_settings.preloadPages',
			);
		}

		return $validation_result;
	}

	/**
	 * Analyze performance impact of settings changes.
	 *
	 * @param array<string, mixed> $current_settings Current settings.
	 * @param array<string, mixed> $new_settings New settings.
	 * @return array<string, mixed> Performance impact analysis.
	 */
	private function analyze_performance_impact( array $current_settings, array $new_settings ): array {
		$impact = array(
			'positive' => array(),
			'negative' => array(),
			'neutral'  => array(),
		);

		// Analyze caching changes
		$current_cache = $current_settings['cache_settings'] ?? array();
		$new_cache     = $new_settings['cache_settings'] ?? array();

		if ( ! ( $current_cache['enablePageCaching'] ?? false ) && ( $new_cache['enablePageCaching'] ?? false ) ) {
			$impact['positive'][] = 'Enabling page caching will significantly improve load times';
		}

		if ( ! ( $current_cache['enableObjectCaching'] ?? false ) && ( $new_cache['enableObjectCaching'] ?? false ) ) {
			$impact['positive'][] = 'Object caching will reduce database queries';
		}

		// Analyze minification changes
		$current_file = $current_settings['file_optimisation'] ?? array();
		$new_file     = $new_settings['file_optimisation'] ?? array();

		if ( ! ( $current_file['minifyCSS'] ?? false ) && ( $new_file['minifyCSS'] ?? false ) ) {
			$impact['positive'][] = 'CSS minification will reduce file sizes';
		}

		if ( ! ( $current_file['minifyJS'] ?? false ) && ( $new_file['minifyJS'] ?? false ) ) {
			$impact['positive'][] = 'JavaScript minification will reduce file sizes';
			$impact['negative'][] = 'JavaScript minification may cause compatibility issues';
		}

		// Analyze image optimization changes
		$current_image = $current_settings['image_optimisation'] ?? array();
		$new_image     = $new_settings['image_optimisation'] ?? array();

		if ( ! ( $current_image['convertImg'] ?? false ) && ( $new_image['convertImg'] ?? false ) ) {
			$impact['positive'][] = 'Image conversion will reduce image file sizes';
		}

		if ( ! ( $current_image['lazyLoadImages'] ?? false ) && ( $new_image['lazyLoadImages'] ?? false ) ) {
			$impact['positive'][] = 'Lazy loading will improve initial page load times';
		}

		return $impact;
	}

	/**
	 * Estimate performance improvement percentage.
	 *
	 * @param array<string, mixed> $settings Settings configuration.
	 * @return int Estimated improvement percentage.
	 */
	private function estimate_performance_improvement( array $settings ): int {
		$improvement = 0;

		// Cache settings impact
		$cache_settings = $settings['cache_settings'] ?? array();
		if ( $cache_settings['enablePageCaching'] ?? false ) {
			$improvement += 30; // Page caching can improve load times by 30-50%
		}
		if ( $cache_settings['enableObjectCaching'] ?? false ) {
			$improvement += 15; // Object caching can reduce database load
		}

		// File optimization impact
		$file_settings = $settings['file_optimisation'] ?? array();
		if ( $file_settings['minifyCSS'] ?? false ) {
			$improvement += 5; // CSS minification typically saves 5-10%
		}
		if ( $file_settings['minifyJS'] ?? false ) {
			$improvement += 5; // JS minification typically saves 5-10%
		}
		if ( $file_settings['combineCSS'] ?? false ) {
			$improvement += 3; // Combining files reduces HTTP requests
		}

		// Image optimization impact
		$image_settings = $settings['image_optimisation'] ?? array();
		if ( $image_settings['lazyLoadImages'] ?? false ) {
			$improvement += 10; // Lazy loading can significantly improve initial load
		}
		if ( $image_settings['convertImg'] ?? false ) {
			$improvement += 15; // WebP conversion can save 25-50% on image sizes
		}

		return min( 80, $improvement ); // Cap at 80% improvement
	}

	/**
	 * Check if site is an e-commerce site.
	 *
	 * @param array<string, mixed> $site_analysis Site analysis data.
	 * @return bool True if e-commerce site detected.
	 */
	private function is_ecommerce_site( array $site_analysis ): bool {
		$ecommerce_plugins = array( 'woocommerce', 'easy-digital-downloads', 'wp-ecommerce' );
		$active_plugins    = array_keys( $site_analysis['plugins']['plugins'] ?? array() );

		return ! empty( array_intersect( $ecommerce_plugins, $active_plugins ) );
	}
}
