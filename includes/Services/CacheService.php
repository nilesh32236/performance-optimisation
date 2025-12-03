<?php
/**
 * Cache Service
 *
 * @package PerformanceOptimisation\Services
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Interfaces\CacheServiceInterface;
use PerformanceOptimisation\Utils\CacheUtil;
use PerformanceOptimisation\Utils\FileSystemUtil;
use PerformanceOptimisation\Utils\LoggingUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CacheService
 *
 * @package PerformanceOptimisation\Services
 */
class CacheService implements CacheServiceInterface {

	private const CACHE_DIR_RELATIVE = '/cache/wppo';

	private string $cache_root_dir;
	private ?PageCacheService $page_cache_service = null;

	public function __construct( ?PageCacheService $page_cache_service = null ) {
		$this->cache_root_dir = wp_normalize_path( WP_CONTENT_DIR . self::CACHE_DIR_RELATIVE );
		$this->page_cache_service = $page_cache_service;
	}

	/**
	 * {@inheritdoc}
	 */
	public function clearCache( string $type = 'all' ): bool {
		// Use PageCacheService for page cache if available
		if ( ( $type === 'page' || $type === 'all' ) && $this->page_cache_service ) {
			$this->page_cache_service->clear_all_cache();
		}
		
		// Use CacheUtil for other cache types
		if ( $type !== 'page' ) {
			return CacheUtil::clearCache( $type );
		}
		
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCacheSize( string $type = 'all' ): string {
		// Use CacheUtil for unified cache size calculation
		return CacheUtil::getCacheSize( $type );
	}

	/**
	 * {@inheritdoc}
	 */
	public function preloadCache( array $urls ): void {
		// Use PageCacheService if available
		if ( $this->page_cache_service ) {
			$warmed = $this->page_cache_service->warm_cache( $urls );
			LoggingUtil::info(
				'Cache preload completed',
				array(
					'total_urls' => count( $urls ),
					'successful' => $warmed,
					'failed'     => count( $urls ) - $warmed,
				)
			);
			return;
		}
		
		// Fallback to CacheUtil
		$results = CacheUtil::warmCache( $urls );
		$successful = array_filter(
			$results,
			function ( $result ) {
				return $result['success'];
			}
		);

		LoggingUtil::info(
			'Cache preload completed',
			array(
				'total_urls' => count( $urls ),
				'successful' => count( $successful ),
				'failed'     => count( $urls ) - count( $successful ),
			)
		);
	}

	/**
	 * Warms up the entire cache by preloading all cacheable pages.
	 */
	public function warmUpCache(): void {
		$post_ids = $this->get_all_cacheable_post_ids();
		$urls     = array_filter( array_map( 'get_permalink', $post_ids ) );

		LoggingUtil::info( 'Starting cache warm-up', array( 'total_pages' => count( $urls ) ) );
		$this->preloadCache( $urls );
	}

	/**
	 * {@inheritdoc}
	 */
	public function invalidateCache( string $pattern ): bool {
		// Handle post ID invalidation
		if ( is_numeric( $pattern ) ) {
			return $this->invalidatePostCache( (int) $pattern );
		}

		// Handle URL pattern invalidation
		return CacheUtil::invalidateCache( $pattern, 'page' );
	}

	/**
	 * Invalidate cache for a specific post and related pages.
	 *
	 * @param int $post_id Post ID to invalidate.
	 * @return bool True on success, false on failure.
	 */
	public function invalidatePostCache( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$urls_to_invalidate = $this->getRelatedUrls( $post );
		
		// Use PageCacheService if available
		if ( $this->page_cache_service ) {
			foreach ( array_unique( $urls_to_invalidate ) as $url ) {
				$this->page_cache_service->clear_url_cache( $url );
			}
		} else {
			$this->invalidateUrls( array_unique( $urls_to_invalidate ) );
		}

		LoggingUtil::info(
			'Post cache invalidated',
			array(
				'post_id'          => $post_id,
				'urls_invalidated' => count( $urls_to_invalidate ),
			)
		);

		return true;
	}

	/**
	 * Invalidate a list of URLs.
	 *
	 * @param array $urls URLs to invalidate.
	 */
	public function invalidateUrls( array $urls ): void {
		foreach ( $urls as $url ) {
			$url_path = trim( wp_parse_url( $url, PHP_URL_PATH ), '/' );
			CacheUtil::invalidateCache( $url_path, 'page' );
		}
	}

	/**
	 * Get URLs related to a post for cache invalidation.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array Array of related URLs.
	 */
	private function getRelatedUrls( \WP_Post $post ): array {
		$urls = array();

		// Post permalink
		$permalink = get_permalink( $post->ID );
		if ( $permalink && ! is_wp_error( $permalink ) ) {
			$urls[] = $permalink;
		}

		// Home page and blog page
		$urls[]        = home_url( '/' );
		$posts_page_id = get_option( 'page_for_posts' );
		if ( $posts_page_id ) {
			$posts_page_url = get_permalink( $posts_page_id );
			if ( $posts_page_url ) {
				$urls[] = $posts_page_url;
			}
		}

		// Author page
		$author_url = get_author_posts_url( $post->post_author );
		if ( $author_url ) {
			$urls[] = $author_url;
		}

		// Term archives
		$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post->ID, $taxonomy );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$term_link = get_term_link( $term );
					if ( $term_link && ! is_wp_error( $term_link ) ) {
						$urls[] = $term_link;
					}
				}
			}
		}

		return $urls;
	}

	private function get_all_cacheable_post_ids(): array {
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		$excluded_post_types = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'user_request' );
		$post_types          = array_diff( $post_types, $excluded_post_types );
		$post_types          = array_unique( array_merge( array_values( $post_types ), array( 'page', 'post' ) ) );

		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$post_ids = get_posts( $query_args );

		$front_page_id = get_option( 'page_on_front' );
		if ( $front_page_id && 'page' === get_post_type( (int) $front_page_id ) && ! in_array( (int) $front_page_id, $post_ids, true ) ) {
			array_unshift( $post_ids, (int) $front_page_id );
		}
		$posts_page_id = get_option( 'page_for_posts' );
		if ( $posts_page_id && 'page' === get_post_type( (int) $posts_page_id ) && ! in_array( (int) $posts_page_id, $post_ids, true ) ) {
			$post_ids[] = (int) $posts_page_id;
		}

		return array_unique( array_map( 'intval', $post_ids ) );
	}

	/**
	 * Get comprehensive cache statistics.
	 *
	 * @return array Cache statistics.
	 */
	public function getCacheStats(): array {
		$stats = CacheUtil::getCacheStats();
		
		// Merge PageCacheService stats if available
		if ( $this->page_cache_service ) {
			$page_stats = $this->page_cache_service->get_cache_stats();
			$stats['types']['page'] = array(
				'enabled' => $page_stats['enabled'] ?? false,
				'files' => $page_stats['files'] ?? 0,
				'size' => $page_stats['size'] ?? '0 B',
				'hit_rate' => $page_stats['hit_rate'] ?? 0,
			);
		}
		
		return $stats;
	}

	/**
	 * Check if cache is enabled for a specific type.
	 *
	 * @param string $type Cache type.
	 * @return bool True if enabled, false otherwise.
	 */
	public function isCacheEnabled( string $type ): bool {
		return CacheUtil::isCacheEnabled( $type );
	}

	/**
	 * Purge cache by pattern.
	 *
	 * @param string $pattern Pattern to match.
	 * @param string $type    Cache type.
	 * @return bool True on success, false on failure.
	 */
	public function purgeCacheByPattern( string $pattern, string $type = 'page' ): bool {
		return CacheUtil::purgeCacheByPattern( $pattern, $type );
	}

	/**
	 * Set cache expiry for a specific type.
	 *
	 * @param string $type    Cache type.
	 * @param int    $seconds Expiry time in seconds.
	 */
	public function setCacheExpiry( string $type, int $seconds ): void {
		CacheUtil::setCacheExpiry( $type, $seconds );
	}

	/**
	 * Get cache expiry for a specific type.
	 *
	 * @param string $type Cache type.
	 * @return int Expiry time in seconds.
	 */
	public function getCacheExpiry( string $type ): int {
		return CacheUtil::getCacheExpiry( $type );
	}

	/**
	 * Intelligent cache warming based on analytics and user behavior.
	 *
	 * @param array $options Warming options.
	 * @return array Warming results.
	 */
	public function intelligentCacheWarming( array $options = array() ): array {
		$defaults = array(
			'priority_pages'      => true,
			'popular_content'     => true,
			'recent_content'      => true,
			'max_pages'           => 50,
			'concurrent_requests' => 3,
		);

		$options      = array_merge( $defaults, $options );
		$urls_to_warm = array();

		// Priority pages (home, about, contact, etc.)
		if ( $options['priority_pages'] ) {
			$urls_to_warm = array_merge( $urls_to_warm, $this->getPriorityPages() );
		}

		// Popular content based on views/comments
		if ( $options['popular_content'] ) {
			$urls_to_warm = array_merge( $urls_to_warm, $this->getPopularContent( 20 ) );
		}

		// Recent content
		if ( $options['recent_content'] ) {
			$urls_to_warm = array_merge( $urls_to_warm, $this->getRecentContent( 15 ) );
		}

		// Limit total URLs
		$urls_to_warm = array_unique( $urls_to_warm );
		if ( count( $urls_to_warm ) > $options['max_pages'] ) {
			$urls_to_warm = array_slice( $urls_to_warm, 0, $options['max_pages'] );
		}

		LoggingUtil::info(
			'Starting intelligent cache warming',
			array(
				'total_urls' => count( $urls_to_warm ),
				'options'    => $options,
			)
		);

		// Warm cache with performance tracking
		PerformanceUtil::startTimer( 'cache_warming' );
		$results  = CacheUtil::warmCache( $urls_to_warm );
		$duration = PerformanceUtil::endTimer( 'cache_warming' );

		$successful = array_filter(
			$results,
			function ( $result ) {
				return $result['success'];
			}
		);

		$warming_stats = array(
			'total_urls'      => count( $urls_to_warm ),
			'successful'      => count( $successful ),
			'failed'          => count( $urls_to_warm ) - count( $successful ),
			'duration'        => $duration,
			'urls_per_second' => $duration > 0 ? count( $urls_to_warm ) / $duration : 0,
		);

		LoggingUtil::info( 'Intelligent cache warming completed', $warming_stats );

		return array(
			'stats'   => $warming_stats,
			'results' => $results,
		);
	}

	/**
	 * Smart cache invalidation with dependency tracking.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $options Invalidation options.
	 * @return array Invalidation results.
	 */
	public function smartCacheInvalidation( int $post_id, array $options = array() ): array {
		$defaults = array(
			'invalidate_related'  => true,
			'invalidate_feeds'    => true,
			'invalidate_sitemaps' => true,
			'cascade_taxonomies'  => true,
		);

		$options          = array_merge( $defaults, $options );
		$invalidated_urls = array();

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array(
				'success' => false,
				'error'   => 'Post not found',
			);
		}

		// Basic post-related URLs
		$basic_urls       = $this->getRelatedUrls( $post );
		$invalidated_urls = array_merge( $invalidated_urls, $basic_urls );

		// Extended invalidation based on options
		if ( $options['invalidate_feeds'] ) {
			$invalidated_urls = array_merge( $invalidated_urls, $this->getFeedUrls() );
		}

		if ( $options['invalidate_sitemaps'] ) {
			$invalidated_urls = array_merge( $invalidated_urls, $this->getSitemapUrls() );
		}

		if ( $options['cascade_taxonomies'] ) {
			$invalidated_urls = array_merge( $invalidated_urls, $this->getCascadeTaxonomyUrls( $post ) );
		}

		// Remove duplicates
		$invalidated_urls = array_unique( $invalidated_urls );

		// Perform invalidation
		$this->invalidateUrls( $invalidated_urls );

		$result = array(
			'success'          => true,
			'post_id'          => $post_id,
			'urls_invalidated' => count( $invalidated_urls ),
			'invalidated_urls' => $invalidated_urls,
		);

		LoggingUtil::info( 'Smart cache invalidation completed', $result );

		return $result;
	}

	/**
	 * Cache performance monitoring and optimization suggestions.
	 *
	 * @return array Performance analysis results.
	 */
	public function analyzeCachePerformance(): array {
		$stats    = $this->getCacheStats();
		$analysis = array(
			'overall_score'   => 0,
			'recommendations' => array(),
			'metrics'         => $stats,
		);

		// Analyze cache hit ratio
		if ( isset( $stats['hit_ratio'] ) ) {
			if ( $stats['hit_ratio'] < 70 ) {
				$analysis['recommendations'][] = array(
					'type'       => 'hit_ratio',
					'severity'   => 'high',
					'message'    => 'Cache hit ratio is below 70%',
					'suggestion' => 'Review cache expiry settings and enable more cache types',
				);
			}
		}

		// Analyze cache size
		if ( $stats['total_size'] > 1073741824 ) { // 1GB
			$analysis['recommendations'][] = array(
				'type'       => 'cache_size',
				'severity'   => 'medium',
				'message'    => 'Cache size exceeds 1GB',
				'suggestion' => 'Consider implementing cache cleanup policies',
			);
		}

		// Analyze cache types
		foreach ( $stats['types'] as $type => $type_stats ) {
			if ( ! $type_stats['enabled'] && in_array( $type, array( 'page', 'object' ), true ) ) {
				$analysis['recommendations'][] = array(
					'type'       => 'cache_disabled',
					'severity'   => 'medium',
					'message'    => ucfirst( $type ) . ' cache is disabled',
					'suggestion' => 'Enable ' . $type . ' cache for better performance',
				);
			}
		}

		// Calculate overall score
		$base_score                = 100;
		$base_score               -= count( $analysis['recommendations'] ) * 10;
		$analysis['overall_score'] = max( 0, $base_score );

		return $analysis;
	}

	/**
	 * Preemptive cache warming based on user behavior patterns.
	 *
	 * @return void
	 */
	public function preemptiveCacheWarming(): void {
		// Get pages that are likely to be visited based on patterns
		$predicted_urls = $this->predictPopularUrls();

		if ( ! empty( $predicted_urls ) ) {
			LoggingUtil::info(
				'Starting preemptive cache warming',
				array(
					'predicted_urls' => count( $predicted_urls ),
				)
			);

			$this->preloadCache( $predicted_urls );
		}
	}

	/**
	 * Get priority pages for cache warming.
	 *
	 * @return array Array of priority page URLs.
	 */
	private function getPriorityPages(): array {
		$urls = array();

		// Home page
		$urls[] = home_url( '/' );

		// Static pages
		$static_pages = get_pages(
			array(
				'meta_key'     => '_wp_page_template',
				'meta_value'   => array( 'page-about.php', 'page-contact.php', 'page-services.php' ),
				'meta_compare' => 'IN',
			)
		);

		foreach ( $static_pages as $page ) {
			$urls[] = get_permalink( $page->ID );
		}

		// Menu pages
		$menu_locations = get_nav_menu_locations();
		foreach ( $menu_locations as $location => $menu_id ) {
			$menu_items = wp_get_nav_menu_items( $menu_id );
			if ( $menu_items ) {
				foreach ( array_slice( $menu_items, 0, 5 ) as $item ) { // Top 5 menu items
					if ( $item->url ) {
						$urls[] = $item->url;
					}
				}
			}
		}

		return array_filter( $urls );
	}

	/**
	 * Get popular content URLs.
	 *
	 * @param int $limit Number of URLs to return.
	 * @return array Array of popular content URLs.
	 */
	private function getPopularContent( int $limit = 20 ): array {
		$args = array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'meta_key'       => 'post_views_count',
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
		);

		$popular_posts = get_posts( $args );

		// Fallback to comment count if no view count
		if ( empty( $popular_posts ) ) {
			$args['meta_key'] = '';
			$args['orderby']  = 'comment_count';
			$popular_posts    = get_posts( $args );
		}

		return array_map( 'get_permalink', wp_list_pluck( $popular_posts, 'ID' ) );
	}

	/**
	 * Get recent content URLs.
	 *
	 * @param int $limit Number of URLs to return.
	 * @return array Array of recent content URLs.
	 */
	private function getRecentContent( int $limit = 15 ): array {
		$args = array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$recent_posts = get_posts( $args );
		return array_map( 'get_permalink', wp_list_pluck( $recent_posts, 'ID' ) );
	}

	/**
	 * Get feed URLs for invalidation.
	 *
	 * @return array Array of feed URLs.
	 */
	private function getFeedUrls(): array {
		return array(
			get_feed_link(),
			get_feed_link( 'rss2' ),
			get_feed_link( 'atom' ),
			get_feed_link( 'comments_rss2' ),
		);
	}

	/**
	 * Get sitemap URLs for invalidation.
	 *
	 * @return array Array of sitemap URLs.
	 */
	private function getSitemapUrls(): array {
		$urls = array();

		// WordPress core sitemaps
		if ( function_exists( 'wp_sitemaps_get_server' ) ) {
			$urls[] = home_url( '/wp-sitemap.xml' );
			$urls[] = home_url( '/wp-sitemap-posts-post-1.xml' );
			$urls[] = home_url( '/wp-sitemap-posts-page-1.xml' );
		}

		// Common SEO plugin sitemaps
		$urls[] = home_url( '/sitemap.xml' );
		$urls[] = home_url( '/sitemap_index.xml' );

		return $urls;
	}

	/**
	 * Get cascade taxonomy URLs for invalidation.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array Array of taxonomy URLs.
	 */
	private function getCascadeTaxonomyUrls( \WP_Post $post ): array {
		$urls = array();

		// Get parent terms and their archives
		$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post->ID, $taxonomy );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					// Parent term archives
					$parent_terms = get_ancestors( $term->term_id, $taxonomy );
					foreach ( $parent_terms as $parent_id ) {
						$parent_term = get_term( $parent_id, $taxonomy );
						if ( $parent_term && ! is_wp_error( $parent_term ) ) {
							$parent_link = get_term_link( $parent_term );
							if ( $parent_link && ! is_wp_error( $parent_link ) ) {
								$urls[] = $parent_link;
							}
						}
					}
				}
			}
		}

		return $urls;
	}

	/**
	 * Predict popular URLs based on patterns.
	 *
	 * @return array Array of predicted popular URLs.
	 */
	private function predictPopularUrls(): array {
		$urls = array();

		// Get trending posts (posts with recent activity)
		$trending_args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'date_query'     => array(
				array(
					'after' => '1 week ago',
				),
			),
			'orderby'        => 'comment_count',
			'order'          => 'DESC',
		);

		$trending_posts = get_posts( $trending_args );
		foreach ( $trending_posts as $post ) {
			$urls[] = get_permalink( $post->ID );
		}

		// Add seasonal/time-based content
		$current_month = date( 'n' );
		$seasonal_args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'meta_query'     => array(
				array(
					'key'     => 'seasonal_month',
					'value'   => $current_month,
					'compare' => '=',
				),
			),
		);

		$seasonal_posts = get_posts( $seasonal_args );
		foreach ( $seasonal_posts as $post ) {
			$urls[] = get_permalink( $post->ID );
		}

		return array_unique( array_filter( $urls ) );
	}
}
