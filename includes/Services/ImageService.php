<?php
/**
 * Image Service
 *
 * @package PerformanceOptimisation\Services
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Interfaces\ImageServiceInterface;
use PerformanceOptimisation\Utils\FileSystemUtil;
use PerformanceOptimisation\Utils\LoggingUtil;
use PerformanceOptimisation\Utils\ValidationUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ImageService
 *
 * @package PerformanceOptimisation\Services
 */
class ImageService implements ImageServiceInterface {

private array $options;

public function __construct( ImageProcessor $imageProcessor, ConversionQueue $conversionQueue, array $settings ) {
	$this->imageProcessor  = $imageProcessor;
	$this->conversionQueue = $conversionQueue;
	$this->settings        = $settings;
}

	/**
	 * {@inheritdoc}
	 */
public function convert_image( string $source_image_path, string $target_format = 'webp' ): bool {
	$target_image_path = $this->get_img_path( $source_image_path, $target_format );
	$quality           = $this->settings['quality'] ?? 82;

	if ( FileSystemUtil::fileExists( $target_image_path ) ) {
		$this->conversionQueue->update_status( $source_image_path, $target_format, 'completed' );
		return true;
	}

	$success = $this->imageProcessor->convert( $source_image_path, $target_image_path, $target_format, $quality );

	if ( $success ) {
		$this->conversionQueue->update_status( $source_image_path, $target_format, 'completed' );
	} else {
		$this->conversionQueue->update_status( $source_image_path, $target_format, 'failed' );
	}

	return $success;
}

	/**
	 * {@inheritdoc}
	 */
public function process_uploaded_image( int $attachment_id ): void {
	$file_path = get_attached_file( $attachment_id );
	if ( ! $file_path ) {
		return;
	}

	$formats = $this->get_target_formats();
	foreach ( $formats as $format ) {
		$this->conversionQueue->add( $file_path, $format );
	}

	$this->conversionQueue->save();
}

	/**
	 * {@inheritdoc}
	 */
public function get_conversion_stats(): array {
	return $this->conversionQueue->get_stats();
}

	/**
	 * {@inheritdoc}
	 */
public function enable_lazy_loading( string $content ): string {
	// A simple lazy loading implementation. More advanced features can be added later.
	$content = preg_replace_callback(
		'/<img([^>]+)src=["\']([^"\\]+)["\\])([^>]*)>/i',
		function ( $matches ) {
			if ( strpos( $matches[1] . $matches[3], 'data-src' ) !== false || strpos( $matches[1] . $matches[3], 'data-lazy-src' ) !== false ) {
				return $matches[0];
			}
			$new_attributes = ' src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" data-src="' . esc_attr( $matches[2] ) . '"';
			return '<img' . $matches[1] . $new_attributes . $matches[3] . ' class="lazyload">';
		},
		$content
	);

	return $content;
}

private function get_target_formats(): array {
	$formats = array();
	if ( ! empty( $this->settings['webp_conversion'] ) ) {
		$formats[] = 'webp';
	}
	if ( ! empty( $this->settings['avif_conversion'] ) ) {
		$formats[] = 'avif';
	}
	return $formats;
}

private function get_img_path( string $source_image_local_path, string $target_format = 'webp' ): string {
		$normalized_source_path = wp_normalize_path( $source_image_local_path );
		$wp_content_dir         = wp_normalize_path( WP_CONTENT_DIR );

		$filename_no_ext = pathinfo( $normalized_source_path, PATHINFO_FILENAME );
		$new_filename    = $filename_no_ext . '.' . $target_format;

		if ( str_starts_with( $normalized_source_path, $wp_content_dir ) ) {
			$relative_path_from_content = ltrim( str_replace( $wp_content_dir, '', $normalized_source_path ), '/\' );
			$original_dirname_relative  = dirname( $relative_path_from_content );

			$new_image_dir_absolute = wp_normalize_path( $wp_content_dir . ' / wppo / ' . $original_dirname_relative );
		} else {
			$abspath                    = wp_normalize_path( ABSPATH );
			$relative_path_from_abspath = ltrim( str_replace( $abspath, '', $normalized_source_path ), ' / \' );
			$original_dirname_relative  = dirname( $relative_path_from_abspath );

			$new_image_dir_absolute = wp_normalize_path( $wp_content_dir . ' / wppo / ' . $original_dirname_relative );
		}

		FileSystemUtil::createDirectory( $new_image_dir_absolute );

		return trailingslashit( $new_image_dir_absolute ) . $new_filename;
	}

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
			$selected_post_types = (array) ( $image_optimisation_settings['selectedPostType'] ?? [] );
			if ( is_singular( $selected_post_types ) && has_post_thumbnail() ) {
				$thumbnail_id = get_post_thumbnail_id();
				if ( $thumbnail_id ) {
					$this->preload_featured_image( $thumbnail_id, $image_optimisation_settings );
				}
			}
		}
	}

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

		echo ' < link rel               = 'preload' href = "' . esc_url( $actual_image_url ) . '" as = 'image' media = "' . esc_attr( $media_query ) . '" > ';
	}

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

		$sources_for_preload = [];
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

	private function parse_srcset( string $srcset ): array {
		$parsed_sources = [];
		$sources        = explode( ', ', $srcset );
		foreach ( $sources as $source ) {
			$parts = preg_split( ' / \s + / ', trim( $source ) );
			if ( count( $parts ) >= 2 && str_ends_with( $parts[1], 'w' ) ) {
				$parsed_sources[] = [
					'url'   => $parts[0],
					'width' => absint( rtrim( $parts[1], 'w' ) ),
				];
			} elseif ( count( $parts ) === 1 ) {
				$parsed_sources[] = [
					'url'   => $parts[0],
					'width' => 0,
				];
			}
		}
		return $parsed_sources;
	}
}
