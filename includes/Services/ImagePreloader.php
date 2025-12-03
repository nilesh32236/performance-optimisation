<?php
/**
 * Image Preloader Service
 *
 * @package PerformanceOptimisation\Services
 * @since 2.1.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Utils\LoggingUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ImagePreloader
 *
 * Handles image preloading logic.
 *
 * @package PerformanceOptimisation\Services
 */
class ImagePreloader {

	/**
	 * Settings array.
	 *
	 * @var array
	 */
	private array $settings;

	/**
	 * Constructor.
	 *
	 * @param array $settings Plugin settings.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Preload images based on settings.
	 */
	public function preload_images(): void {
		$image_optimisation_settings = $this->settings;

		if ( is_front_page() && ! empty( $image_optimisation_settings['preloadFrontPageImages'] ) ) {
			$front_page_image_urls = explode( "\n", $image_optimisation_settings['preloadFrontPageImagesUrls'] ?? '' );
			foreach ( $front_page_image_urls as $img_url ) {
				$this->generate_preload_link_for_image_url( $img_url );
			}
		}

		if ( is_singular() ) {
			$meta_image_urls_string = get_post_meta( get_the_ID(), '_wppo_preload_image_url', true );
			if ( ! empty( $meta_image_urls_string ) ) {
				$meta_image_urls = explode( "\n", $meta_image_urls_string );
				foreach ( $meta_image_urls as $img_url ) {
					$this->generate_preload_link_for_image_url( $img_url );
				}
			}
		}

		if ( ! empty( $image_optimisation_settings['preloadPostTypeImage'] ) ) {
			$selected_post_types = (array) ( $image_optimisation_settings['selectedPostType'] ?? array() );
			if ( is_singular( $selected_post_types ) && has_post_thumbnail() ) {
				$thumbnail_id = get_post_thumbnail_id();
				if ( $thumbnail_id ) {
					$this->preload_featured_image( $thumbnail_id, $image_optimisation_settings );
				}
			}
		}
	}

	/**
	 * Generate preload link for image URL.
	 *
	 * @param string $image_url_config Image URL configuration.
	 */
	private function generate_preload_link_for_image_url( string $image_url_config ): void {
		$image_url_config = trim( $image_url_config );
		$media_query      = '';
		$actual_image_url = $image_url_config;

		if ( str_starts_with( $image_url_config, 'mobile:' ) ) {
			$actual_image_url = trim( str_replace( 'mobile:', '', $image_url_config ) );
			$media_query      = '( max - width: 768px )';
		} elseif ( str_starts_with( $image_url_config, 'desktop:' ) ) {
			$actual_image_url = trim( str_replace( 'desktop:', '', $image_url_config ) );
			$media_query      = '( min - width: 769px )';
		}

		if ( ! preg_match( ' / ^ https ?: \]\ / \ / i', $actual_image_url ) ) {
			$actual_image_url = content_url( ltrim( $actual_image_url, ' / ' ) );
		}

		echo '<link rel="preload" href="' . esc_url( $actual_image_url ) . '" as="image" media="' . esc_attr( $media_query ) . '">';
	}

	/**
	 * Preload featured image.
	 *
	 * @param int   $thumbnail_id Thumbnail ID.
	 * @param array $settings     Settings array.
	 */
	private function preload_featured_image( int $thumbnail_id, array $settings ): void {
		$default_image_size = ( 'product' === get_post_type() && class_exists( 'WooCommerce' ) ) ? 'woocommerce_single' : 'large';
		$default_image_url  = wp_get_attachment_image_url( $thumbnail_id, $default_image_size );

		if ( ! $default_image_url ) {
			return;
		}

		$srcset = wp_get_attachment_image_srcset( $thumbnail_id, $default_image_size );
		if ( ! $srcset ) {
			$this->generate_preload_link_for_image_url( $default_image_url );
			return;
		}

		$parsed_srcset = $this->parse_srcset( $srcset );
		$max_width     = $settings['maxWidthImgSize'] ?? 1478;

		$sources_for_preload = array();
		foreach ( $parsed_srcset as $source ) {
			if ( $source['width'] <= $max_width ) {
				$sources_for_preload[] = $source;
			}
		}

		usort(
			$sources_for_preload,
			function ( $a, $b ) {
				return $a['width'] <=> $b['width'];
			}
		);

		$previous_width = 0;
		foreach ( $sources_for_preload as $index => $source ) {
			$current_width = $source['width'];
			$media_query   = "(min-width: {$previous_width}px)";

			if ( isset( $sources_for_preload[ $index + 1 ] ) ) {
				$media_query .= " and (max-width: {$current_width}px)";
			}

			$this->generate_preload_link_for_image_url( $source['url'] );
			$previous_width = $current_width + 1;
		}
	}

	/**
	 * Parse srcset string.
	 *
	 * @param string $srcset Srcset string.
	 * @return array Parsed sources.
	 */
	private function parse_srcset( string $srcset ): array {
		$parsed_sources = array();
		$sources        = explode( ', ', $srcset );
		foreach ( $sources as $source ) {
			$parts = preg_split( ' / \s + / ', trim( $source ) );
			if ( count( $parts ) >= 2 && str_ends_with( $parts[1], 'w' ) ) {
				$parsed_sources[] = array(
					'url'   => $parts[0],
					'width' => absint( rtrim( $parts[1], 'w' ) ),
				);
			} elseif ( count( $parts ) === 1 ) {
				$parsed_sources[] = array(
					'url'   => $parts[0],
					'width' => 0,
				);
			}
		}
		return $parsed_sources;
	}
}
