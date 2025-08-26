<?php
/**
 * Cache Service
 *
 * @package PerformanceOptimisation\Services
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Interfaces\CacheServiceInterface;
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

	public function __construct() {
		$this->cache_root_dir = wp_normalize_path( WP_CONTENT_DIR . self::CACHE_DIR_RELATIVE );
	}

	/**
	 * {@inheritdoc}
	 */
	public function clearCache( string $type = 'all' ): bool {
		try {
			$domain = $this->get_domain();
			if ( 'all' === $type ) {
				$this->clear_all_cache( $domain );
			} else {
				$this->clear_specific_cache( $type, $domain );
			}
			LoggingUtil::info( "Cache cleared: {$type}" );
			return true;
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Failed to clear cache: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCacheSize( string $type = 'all' ): string {
		try {
			$domain = $this->get_domain();
			$total_size = 0;
			if ( 'all' === $type ) {
				$total_size = FileSystemUtil::getDirectorySize( $this->cache_root_dir );
			} else {
				$cache_dir = $this->get_cache_dir_for_type( $type, $domain );
				if ( FileSystemUtil::isDirectory( $cache_dir ) ) {
					$total_size = FileSystemUtil::getDirectorySize( $cache_dir );
				}
			}
			return size_format( $total_size );
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Failed to get cache size: ' . $e->getMessage() );
			return '0 B';
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function preloadCache( array $urls ): void {
		$delay = 5;
		foreach ( $urls as $url ) {
			$post_id = url_to_postid( $url );
			if ( $post_id > 0 ) {
				if ( ! wp_next_scheduled( 'wppo_generate_static_page', array( $post_id ) ) ) {
					wp_schedule_single_event( time() + $delay, 'wppo_generate_static_page', array( $post_id ) );
					$delay += wp_rand( 5, 15 );
				}
			}
		}
	}

	/**
	 * Warms up the entire cache by preloading all cacheable pages.
	 */
	public function warmUpCache(): void {
		$post_ids = $this->get_all_cacheable_post_ids();
		$urls = array_map( 'get_permalink', $post_ids );
		$this->preloadCache( array_filter( $urls ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function invalidateCache( string $pattern ): bool {
		$post_id = (int) $pattern;
		$post = get_post( $post_id );

		if ( ! $post ) {
			return false;
		}

		$urls_to_invalidate = array();

		// Post permalink
		$permalink = get_permalink( $post_id );
		if ( $permalink && ! is_wp_error( $permalink ) ) {
			$urls_to_invalidate[] = $permalink;
		}

		// Home page and blog page
		$urls_to_invalidate[] = home_url( '/' );
		$posts_page_id = get_option( 'page_for_posts' );
		if ( $posts_page_id ) {
			$urls_to_invalidate[] = get_permalink( $posts_page_id );
		}

		// Author page
		$author_url = get_author_posts_url( $post->post_author );
		if ( $author_url ) {
			$urls_to_invalidate[] = $author_url;
		}

		// Term archives
		$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$term_link = get_term_link( $term );
					if ( $term_link && ! is_wp_error( $term_link ) ) {
						$urls_to_invalidate[] = $term_link;
					}
				}
			}
		}

		$this->invalidateUrls( array_unique( $urls_to_invalidate ) );

		return true;
	}

	/**
	 * Invalidate a list of URLs.
	 *
	 * @param array $urls
	 */
	public function invalidateUrls( array $urls ): void {
		foreach ( $urls as $url ) {
			$this->clear_cache_for_url( $url );
		}
	}

	private function clear_cache_for_url( string $url ): void {
		$url_path = trim( wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$file_path = $this->get_cache_file_path_for_post_url( $url_path, 'html' );
		$this->delete_single_cache_file_pair( $file_path );
	}

	private function get_domain(): string {
		$domain = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		return preg_replace( '/:\d+$/', '', $domain );
	}

	private function clear_all_cache( string $domain ): void {
		$page_cache_dir = $this->get_cache_dir_for_type( 'page', $domain );
		if ( FileSystemUtil::isDirectory( $page_cache_dir ) ) {
			FileSystemUtil::deleteDirectory( $page_cache_dir, true );
		}
		$minified_cache_dir = $this->get_cache_dir_for_type( 'minified', $domain );
		if ( FileSystemUtil::isDirectory( $minified_cache_dir ) ) {
			FileSystemUtil::deleteDirectory( $minified_cache_dir, true );
		}
	}

	private function clear_specific_cache( string $type, string $domain ): void {
		$cache_dir = $this->get_cache_dir_for_type( $type, $domain );
		if ( FileSystemUtil::isDirectory( $cache_dir ) ) {
			FileSystemUtil::deleteDirectory( $cache_dir, true );
		}
	}

	private function get_cache_dir_for_type( string $type, string $domain ): string {
		switch ( $type ) {
			case 'page':
				return wp_normalize_path( trailingslashit( $this->cache_root_dir ) . $domain );
			case 'minified':
				return wp_normalize_path( trailingslashit( $this->cache_root_dir ) . 'min' );
			default:
				return '';
		}
	}

	private function get_cache_file_path_for_post_url( string $url_path, string $type = 'html' ): string {
		$domain = $this->get_domain();
		$path_suffix = empty( $url_path ) ? 'index.' . $type : trailingslashit( $url_path ) . 'index.' . $type;
		return wp_normalize_path( trailingslashit( $this->cache_root_dir ) . trailingslashit( $domain ) . $path_suffix );
	}

	private function delete_single_cache_file_pair( string $file_path ): void {
		if ( FileSystemUtil::fileExists( $file_path ) ) {
			FileSystemUtil::deleteFile( $file_path );
		}
		$gzip_file_path = $file_path . '.gz';
		if ( FileSystemUtil::fileExists( $gzip_file_path ) ) {
			FileSystemUtil::deleteFile( $gzip_file_path );
		}
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
}
