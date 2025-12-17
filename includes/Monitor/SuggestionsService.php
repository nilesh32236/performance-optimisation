<?php
/**
 * Suggestions Service for Performance Optimisation.
 *
 * Provides intelligent performance improvement suggestions based on
 * Lighthouse scores, Core Web Vitals, asset analysis, and system info.
 *
 * @package PerformanceOptimisation
 * @since 2.0.0
 */

declare(strict_types=1);

namespace PerformanceOptimisation\Monitor;

/**
 * Class SuggestionsService
 *
 * Analyzes performance metrics and generates actionable recommendations.
 *
 * @since 2.0.0
 */
class SuggestionsService {

	/**
	 * Priority levels for suggestions.
	 */
	const PRIORITY_HIGH   = 'high';
	const PRIORITY_MEDIUM = 'medium';
	const PRIORITY_LOW    = 'low';

	/**
	 * Impact levels for suggestions.
	 */
	const IMPACT_HIGH   = 'high';
	const IMPACT_MEDIUM = 'medium';
	const IMPACT_LOW    = 'low';

	/**
	 * Suggestion categories.
	 */
	const CATEGORY_LIGHTHOUSE = 'lighthouse';
	const CATEGORY_WEB_VITALS = 'web_vitals';
	const CATEGORY_ASSETS     = 'assets';
	const CATEGORY_IMAGES     = 'images';
	const CATEGORY_CACHING    = 'caching';
	const CATEGORY_CODE       = 'code';
	const CATEGORY_SECURITY   = 'security';

	/**
	 * Get all suggestions based on provided data.
	 *
	 * @param array $pagespeed_data PageSpeed API data.
	 * @param array $asset_data     Asset analysis data.
	 * @param array $settings       Current plugin settings.
	 *
	 * @return array Array of suggestions.
	 */
	public function get_all_suggestions( array $pagespeed_data, array $asset_data, array $settings ): array {
		$suggestions = array();

		// Lighthouse score suggestions.
		if ( ! empty( $pagespeed_data['lighthouse'] ) ) {
			$suggestions = array_merge(
				$suggestions,
				$this->get_lighthouse_suggestions( $pagespeed_data['lighthouse'] )
			);
		}

		// Core Web Vitals suggestions.
		if ( ! empty( $pagespeed_data['core_web_vitals'] ) ) {
			$suggestions = array_merge(
				$suggestions,
				$this->get_web_vitals_suggestions( $pagespeed_data['core_web_vitals'] )
			);
		}

		// Asset suggestions.
		if ( ! empty( $asset_data ) ) {
			$suggestions = array_merge(
				$suggestions,
				$this->get_asset_suggestions( $asset_data )
			);
		}

		// Settings-based suggestions.
		$suggestions = array_merge(
			$suggestions,
			$this->get_settings_suggestions( $settings )
		);

		// Sort by priority.
		usort( $suggestions, array( $this, 'sort_by_priority' ) );

		return $suggestions;
	}

	/**
	 * Get Lighthouse score suggestions.
	 *
	 * @param array $lighthouse Lighthouse scores.
	 *
	 * @return array Suggestions array.
	 */
	private function get_lighthouse_suggestions( array $lighthouse ): array {
		$suggestions = array();

		// Performance score.
		if ( isset( $lighthouse['performance'] ) ) {
			$score = (int) $lighthouse['performance'];
			if ( $score < 50 ) {
				$suggestions[] = $this->create_suggestion(
					'Critical: Performance score is poor',
					'Your performance score of ' . $score . ' is critically low. Enable caching, minification, and image optimization to improve.',
					self::PRIORITY_HIGH,
					self::IMPACT_HIGH,
					self::CATEGORY_LIGHTHOUSE,
					array( 'caching', 'minification', 'images' )
				);
			} elseif ( $score < 90 ) {
				$suggestions[] = $this->create_suggestion(
					'Performance score needs improvement',
					'Your performance score of ' . $score . ' can be improved. Consider enabling additional optimizations.',
					self::PRIORITY_MEDIUM,
					self::IMPACT_MEDIUM,
					self::CATEGORY_LIGHTHOUSE,
					array( 'defer_js', 'lazy_load' )
				);
			}
		}

		// Accessibility score.
		if ( isset( $lighthouse['accessibility'] ) ) {
			$score = (int) $lighthouse['accessibility'];
			if ( $score < 70 ) {
				$suggestions[] = $this->create_suggestion(
					'Accessibility needs attention',
					'Accessibility score of ' . $score . ' may affect users with disabilities and SEO rankings.',
					self::PRIORITY_MEDIUM,
					self::IMPACT_MEDIUM,
					self::CATEGORY_LIGHTHOUSE,
					array()
				);
			}
		}

		// Best Practices score.
		if ( isset( $lighthouse['best_practices'] ) ) {
			$score = (int) $lighthouse['best_practices'];
			if ( $score < 80 ) {
				$suggestions[] = $this->create_suggestion(
					'Best practices score is low',
					'Review security headers and deprecated APIs. Score: ' . $score . '.',
					self::PRIORITY_LOW,
					self::IMPACT_LOW,
					self::CATEGORY_SECURITY,
					array()
				);
			}
		}

		// SEO score.
		if ( isset( $lighthouse['seo'] ) ) {
			$score = (int) $lighthouse['seo'];
			if ( $score < 80 ) {
				$suggestions[] = $this->create_suggestion(
					'SEO score could be better',
					'Improve meta tags, structured data, and mobile-friendliness. Score: ' . $score . '.',
					self::PRIORITY_MEDIUM,
					self::IMPACT_MEDIUM,
					self::CATEGORY_LIGHTHOUSE,
					array()
				);
			}
		}

		return $suggestions;
	}

	/**
	 * Get Core Web Vitals suggestions.
	 *
	 * @param array $vitals Core Web Vitals data.
	 *
	 * @return array Suggestions array.
	 */
	private function get_web_vitals_suggestions( array $vitals ): array {
		$suggestions = array();

		// LCP - Largest Contentful Paint (good: < 2.5s, needs improvement: 2.5-4s, poor: > 4s).
		if ( isset( $vitals['lcp'] ) ) {
			$lcp_ms = $this->parse_time_value( $vitals['lcp'] );
			if ( $lcp_ms > 4000 ) {
				$suggestions[] = $this->create_suggestion(
					'Critical: LCP is too slow (' . $vitals['lcp'] . ')',
					'Largest Contentful Paint should be under 2.5 seconds. Enable image optimization, lazy loading, and page caching.',
					self::PRIORITY_HIGH,
					self::IMPACT_HIGH,
					self::CATEGORY_WEB_VITALS,
					array( 'caching', 'images', 'lazy_load' )
				);
			} elseif ( $lcp_ms > 2500 ) {
				$suggestions[] = $this->create_suggestion(
					'LCP needs improvement (' . $vitals['lcp'] . ')',
					'LCP is above the 2.5s threshold. Consider preloading critical images and reducing server response time.',
					self::PRIORITY_MEDIUM,
					self::IMPACT_HIGH,
					self::CATEGORY_WEB_VITALS,
					array( 'caching', 'images' )
				);
			}
		}

		// FCP - First Contentful Paint (good: < 1.8s).
		if ( isset( $vitals['fcp'] ) ) {
			$fcp_ms = $this->parse_time_value( $vitals['fcp'] );
			if ( $fcp_ms > 3000 ) {
				$suggestions[] = $this->create_suggestion(
					'FCP is slow (' . $vitals['fcp'] . ')',
					'First Contentful Paint should be under 1.8 seconds. Enable browser caching and defer non-critical JS.',
					self::PRIORITY_HIGH,
					self::IMPACT_MEDIUM,
					self::CATEGORY_WEB_VITALS,
					array( 'defer_js', 'caching' )
				);
			} elseif ( $fcp_ms > 1800 ) {
				$suggestions[] = $this->create_suggestion(
					'FCP needs improvement (' . $vitals['fcp'] . ')',
					'Consider inlining critical CSS and minimizing render-blocking resources.',
					self::PRIORITY_MEDIUM,
					self::IMPACT_MEDIUM,
					self::CATEGORY_WEB_VITALS,
					array( 'minify_css', 'defer_js' )
				);
			}
		}

		// CLS - Cumulative Layout Shift (good: < 0.1).
		if ( isset( $vitals['cls'] ) ) {
			$cls = (float) $vitals['cls'];
			if ( $cls > 0.25 ) {
				$suggestions[] = $this->create_suggestion(
					'Critical: CLS is causing layout shifts (' . $vitals['cls'] . ')',
					'Cumulative Layout Shift should be under 0.1. Add width/height to images and avoid inserting content above existing content.',
					self::PRIORITY_HIGH,
					self::IMPACT_HIGH,
					self::CATEGORY_WEB_VITALS,
					array()
				);
			} elseif ( $cls > 0.1 ) {
				$suggestions[] = $this->create_suggestion(
					'CLS needs improvement (' . $vitals['cls'] . ')',
					'Reserve space for ads, embeds, and lazy-loaded images to reduce layout shifts.',
					self::PRIORITY_MEDIUM,
					self::IMPACT_MEDIUM,
					self::CATEGORY_WEB_VITALS,
					array()
				);
			}
		}

		// TBT - Total Blocking Time (good: < 200ms).
		if ( isset( $vitals['tbt'] ) ) {
			$tbt_ms = $this->parse_time_value( $vitals['tbt'] );
			if ( $tbt_ms > 600 ) {
				$suggestions[] = $this->create_suggestion(
					'Critical: TBT is blocking interactivity (' . $vitals['tbt'] . ')',
					'Total Blocking Time should be under 200ms. Defer or delay JavaScript execution.',
					self::PRIORITY_HIGH,
					self::IMPACT_HIGH,
					self::CATEGORY_WEB_VITALS,
					array( 'defer_js', 'delay_js' )
				);
			} elseif ( $tbt_ms > 200 ) {
				$suggestions[] = $this->create_suggestion(
					'TBT needs improvement (' . $vitals['tbt'] . ')',
					'Consider splitting long JavaScript tasks and enabling delay JS.',
					self::PRIORITY_MEDIUM,
					self::IMPACT_MEDIUM,
					self::CATEGORY_WEB_VITALS,
					array( 'delay_js' )
				);
			}
		}

		// Speed Index (good: < 3.4s).
		if ( isset( $vitals['si'] ) ) {
			$si_ms = $this->parse_time_value( $vitals['si'] );
			if ( $si_ms > 5800 ) {
				$suggestions[] = $this->create_suggestion(
					'Speed Index is poor (' . $vitals['si'] . ')',
					'Page takes too long to visually complete. Enable all optimizations.',
					self::PRIORITY_HIGH,
					self::IMPACT_MEDIUM,
					self::CATEGORY_WEB_VITALS,
					array( 'caching', 'minification', 'images' )
				);
			}
		}

		return $suggestions;
	}

	/**
	 * Get asset-based suggestions.
	 *
	 * @param array $assets Asset analysis data.
	 *
	 * @return array Suggestions array.
	 */
	private function get_asset_suggestions( array $assets ): array {
		$suggestions = array();

		// CSS file count.
		if ( isset( $assets['css']['count'] ) && $assets['css']['count'] > 15 ) {
			$suggestions[] = $this->create_suggestion(
				'Too many CSS files (' . $assets['css']['count'] . ')',
				'Consider combining CSS files to reduce HTTP requests.',
				self::PRIORITY_MEDIUM,
				self::IMPACT_MEDIUM,
				self::CATEGORY_ASSETS,
				array( 'minify_css' )
			);
		}

		// JS file count.
		if ( isset( $assets['js']['count'] ) && $assets['js']['count'] > 20 ) {
			$suggestions[] = $this->create_suggestion(
				'Too many JavaScript files (' . $assets['js']['count'] . ')',
				'Reduce the number of JS files by combining or removing unused scripts.',
				self::PRIORITY_MEDIUM,
				self::IMPACT_MEDIUM,
				self::CATEGORY_ASSETS,
				array( 'minify_js', 'defer_js' )
			);
		}

		// Total CSS size > 500KB.
		if ( isset( $assets['css']['total_size'] ) && $assets['css']['total_size'] > 500000 ) {
			$size_kb       = round( $assets['css']['total_size'] / 1024, 1 );
			$suggestions[] = $this->create_suggestion(
				'CSS payload is too large (' . $size_kb . ' KB)',
				'Minify CSS and remove unused styles to reduce file size.',
				self::PRIORITY_HIGH,
				self::IMPACT_MEDIUM,
				self::CATEGORY_ASSETS,
				array( 'minify_css' )
			);
		}

		// Total JS size > 1MB.
		if ( isset( $assets['js']['total_size'] ) && $assets['js']['total_size'] > 1000000 ) {
			$size_kb       = round( $assets['js']['total_size'] / 1024, 1 );
			$suggestions[] = $this->create_suggestion(
				'JavaScript payload is too large (' . $size_kb . ' KB)',
				'Minify JS, defer loading, and consider lazy-loading non-critical scripts.',
				self::PRIORITY_HIGH,
				self::IMPACT_HIGH,
				self::CATEGORY_ASSETS,
				array( 'minify_js', 'defer_js', 'delay_js' )
			);
		}

		// Image count > 50.
		if ( isset( $assets['images']['count'] ) && $assets['images']['count'] > 50 ) {
			$suggestions[] = $this->create_suggestion(
				'Many images on page (' . $assets['images']['count'] . ')',
				'Enable lazy loading to defer off-screen images.',
				self::PRIORITY_MEDIUM,
				self::IMPACT_MEDIUM,
				self::CATEGORY_IMAGES,
				array( 'lazy_load' )
			);
		}

		// Image size > 2MB.
		if ( isset( $assets['images']['total_size'] ) && $assets['images']['total_size'] > 2000000 ) {
			$size_mb       = round( $assets['images']['total_size'] / 1048576, 1 );
			$suggestions[] = $this->create_suggestion(
				'Images are too heavy (' . $size_mb . ' MB)',
				'Enable WebP conversion and image compression.',
				self::PRIORITY_HIGH,
				self::IMPACT_HIGH,
				self::CATEGORY_IMAGES,
				array( 'images', 'lazy_load' )
			);
		}

		return $suggestions;
	}

	/**
	 * Get settings-based suggestions.
	 *
	 * @param array $settings Current plugin settings.
	 *
	 * @return array Suggestions array.
	 */
	private function get_settings_suggestions( array $settings ): array {
		$suggestions = array();

		// Check if caching is disabled.
		$cache = $settings['cache_settings'] ?? array();
		if ( empty( $cache['page_cache_enabled'] ) ) {
			$suggestions[] = $this->create_suggestion(
				'Page caching is disabled',
				'Enable page caching to serve static HTML and dramatically improve load times.',
				self::PRIORITY_HIGH,
				self::IMPACT_HIGH,
				self::CATEGORY_CACHING,
				array( 'caching' )
			);
		}

		// Check if browser caching is disabled.
		if ( empty( $cache['browser_cache_enabled'] ) ) {
			$suggestions[] = $this->create_suggestion(
				'Browser caching is disabled',
				'Enable browser caching to leverage client-side caching for returning visitors.',
				self::PRIORITY_MEDIUM,
				self::IMPACT_MEDIUM,
				self::CATEGORY_CACHING,
				array( 'caching' )
			);
		}

		// Check minification settings.
		$minify = $settings['minification'] ?? array();
		if ( empty( $minify['minify_css'] ) ) {
			$suggestions[] = $this->create_suggestion(
				'CSS minification is disabled',
				'Enable CSS minification to reduce file sizes.',
				self::PRIORITY_MEDIUM,
				self::IMPACT_MEDIUM,
				self::CATEGORY_CODE,
				array( 'minify_css' )
			);
		}

		if ( empty( $minify['minify_js'] ) ) {
			$suggestions[] = $this->create_suggestion(
				'JavaScript minification is disabled',
				'Enable JS minification to reduce file sizes.',
				self::PRIORITY_MEDIUM,
				self::IMPACT_MEDIUM,
				self::CATEGORY_CODE,
				array( 'minify_js' )
			);
		}

		if ( empty( $minify['minify_html'] ) ) {
			$suggestions[] = $this->create_suggestion(
				'HTML minification is disabled',
				'Enable HTML minification to reduce page size.',
				self::PRIORITY_LOW,
				self::IMPACT_LOW,
				self::CATEGORY_CODE,
				array( 'minify_html' )
			);
		}

		// Check image optimization.
		$images = $settings['image_optimization'] ?? array();
		if ( empty( $images['lazy_load_enabled'] ) ) {
			$suggestions[] = $this->create_suggestion(
				'Lazy loading is disabled',
				'Enable lazy loading to defer off-screen images and improve initial load time.',
				self::PRIORITY_HIGH,
				self::IMPACT_HIGH,
				self::CATEGORY_IMAGES,
				array( 'lazy_load' )
			);
		}

		if ( empty( $images['webp_conversion'] ) ) {
			$suggestions[] = $this->create_suggestion(
				'WebP conversion is disabled',
				'Enable WebP conversion for 25-30% smaller image files.',
				self::PRIORITY_MEDIUM,
				self::IMPACT_MEDIUM,
				self::CATEGORY_IMAGES,
				array( 'images' )
			);
		}

		// Check JS defer/delay.
		$advanced = $settings['advanced'] ?? array();
		if ( empty( $advanced['defer_js'] ) ) {
			$suggestions[] = $this->create_suggestion(
				'JavaScript defer is disabled',
				'Enable defer JS to load scripts after page content, improving INP.',
				self::PRIORITY_MEDIUM,
				self::IMPACT_HIGH,
				self::CATEGORY_CODE,
				array( 'defer_js' )
			);
		}

		return $suggestions;
	}

	/**
	 * Create a suggestion array.
	 *
	 * @param string $title       Suggestion title.
	 * @param string $description Detailed description.
	 * @param string $priority    Priority level.
	 * @param string $impact      Impact level.
	 * @param string $category    Suggestion category.
	 * @param array  $fix_actions Array of setting keys that can fix this issue.
	 *
	 * @return array The suggestion array.
	 */
	private function create_suggestion(
		string $title,
		string $description,
		string $priority,
		string $impact,
		string $category,
		array $fix_actions
	): array {
		return array(
			'title'       => $title,
			'description' => $description,
			'priority'    => $priority,
			'impact'      => $impact,
			'category'    => $category,
			'fix_actions' => $fix_actions,
			'created_at'  => current_time( 'mysql' ),
		);
	}

	/**
	 * Parse time value to milliseconds.
	 *
	 * @param string $value Time value (e.g., "2.4s", "180ms").
	 *
	 * @return float Time in milliseconds.
	 */
	private function parse_time_value( string $value ): float {
		$value = strtolower( trim( $value ) );

		if ( strpos( $value, 'ms' ) !== false ) {
			return (float) str_replace( 'ms', '', $value );
		}

		if ( strpos( $value, 's' ) !== false ) {
			return (float) str_replace( 's', '', $value ) * 1000;
		}

		return (float) $value;
	}

	/**
	 * Sort suggestions by priority.
	 *
	 * @param array $a First suggestion.
	 * @param array $b Second suggestion.
	 *
	 * @return int Comparison result.
	 */
	private function sort_by_priority( array $a, array $b ): int {
		$priority_order = array(
			self::PRIORITY_HIGH   => 1,
			self::PRIORITY_MEDIUM => 2,
			self::PRIORITY_LOW    => 3,
		);

		$a_order = $priority_order[ $a['priority'] ] ?? 4;
		$b_order = $priority_order[ $b['priority'] ] ?? 4;

		return $a_order <=> $b_order;
	}

	/**
	 * Get suggestion count by priority.
	 *
	 * @param array $suggestions Array of suggestions.
	 *
	 * @return array Counts by priority.
	 */
	public function get_priority_counts( array $suggestions ): array {
		$counts = array(
			'high'   => 0,
			'medium' => 0,
			'low'    => 0,
			'total'  => count( $suggestions ),
		);

		foreach ( $suggestions as $suggestion ) {
			$priority = $suggestion['priority'] ?? 'low';
			if ( isset( $counts[ $priority ] ) ) {
				++$counts[ $priority ];
			}
		}

		return $counts;
	}
}
