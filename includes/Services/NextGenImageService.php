<?php
/**
 * Next-Gen Image Service
 *
 * Serves WebP/AVIF images based on browser support
 *
 * @package PerformanceOptimisation\Services
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NextGenImageService
 */
class NextGenImageService {

	private array $settings;
	private array $exclude_images = array();

	public function __construct( array $settings ) {
		$this->settings = $settings;

		if ( ! empty( $settings['exclude_webp_images'] ) ) {
			$this->exclude_images = array_map( 'trim', explode( "\n", $settings['exclude_webp_images'] ) );
		}
	}

	/**
	 * Initialize next-gen image serving.
	 */
	public function init(): void {
		if ( empty( $this->settings['serve_next_gen'] ) ) {
			return;
		}

		add_filter( 'the_content', array( $this, 'serve_next_gen_images' ), 999 );
		add_filter( 'post_thumbnail_html', array( $this, 'serve_next_gen_images' ), 999 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'maybe_serve_next_gen_image' ) );
	}

	/**
	 * Serve next-gen images in content.
	 *
	 * @param string $content HTML content.
	 * @return string Modified content.
	 */
	public function serve_next_gen_images( string $content ): string {
		if ( is_admin() || is_feed() ) {
			return $content;
		}

		$supports_avif = $this->browser_supports_avif();
		$supports_webp = $this->browser_supports_webp();

		if ( ! $supports_avif && ! $supports_webp ) {
			return $content;
		}

		// Replace image URLs
		$content = preg_replace_callback(
			'/<img\b[^>]*((?:src|srcset)=["\'][^"\']+["\'])[^>]*>/i',
			function ( $matches ) use ( $supports_avif, $supports_webp ) {
				$img_tag = $matches[0];

				// Check exclusions
				foreach ( $this->exclude_images as $exclude ) {
					if ( ! empty( $exclude ) && strpos( $img_tag, $exclude ) !== false ) {
						return $img_tag;
					}
				}

				// Replace src
				$img_tag = preg_replace_callback(
					'/src=["\']([^"\']+)["\']/i',
					function ( $src_match ) use ( $supports_avif, $supports_webp ) {
						$url     = $src_match[1];
						$new_url = $this->get_next_gen_url( $url, $supports_avif, $supports_webp );
						return 'src="' . esc_attr( $new_url ) . '"';
					},
					$img_tag
				);

				// Replace srcset
				$img_tag = preg_replace_callback(
					'/srcset=["\']([^"\']+)["\']/i',
					function ( $srcset_match ) use ( $supports_avif, $supports_webp ) {
						$srcset     = $srcset_match[1];
						$new_srcset = implode(
							', ',
							array_map(
								function ( $srcset_item ) use ( $supports_avif, $supports_webp ) {
									list( $url, $descriptor ) = array_pad( explode( ' ', trim( $srcset_item ), 2 ), 2, '' );
									$new_url                  = $this->get_next_gen_url( $url, $supports_avif, $supports_webp );
									return $new_url . ( $descriptor ? " $descriptor" : '' );
								},
								explode( ',', $srcset )
							)
						);
						return 'srcset="' . esc_attr( $new_srcset ) . '"';
					},
					$img_tag
				);

				return $img_tag;
			},
			$content
		);

		return $content;
	}

	/**
	 * Maybe serve next-gen image for attachment.
	 *
	 * @param array $image Image source array.
	 * @return array Modified image source.
	 */
	public function maybe_serve_next_gen_image( $image ): array {
		if ( empty( $image[0] ) ) {
			return $image;
		}

		$supports_avif = $this->browser_supports_avif();
		$supports_webp = $this->browser_supports_webp();

		if ( ! $supports_avif && ! $supports_webp ) {
			return $image;
		}

		$image[0] = $this->get_next_gen_url( $image[0], $supports_avif, $supports_webp );

		return $image;
	}

	/**
	 * Get next-gen image URL.
	 *
	 * @param string $url           Original image URL.
	 * @param bool   $supports_avif Whether browser supports AVIF.
	 * @param bool   $supports_webp Whether browser supports WebP.
	 * @return string Next-gen image URL or original.
	 */
	private function get_next_gen_url( string $url, bool $supports_avif, bool $supports_webp ): string {
		// Skip external URLs
		if ( strpos( $url, home_url() ) === false ) {
			return $url;
		}

		// Skip if already next-gen format
		$ext = pathinfo( $url, PATHINFO_EXTENSION );
		if ( in_array( strtolower( $ext ), array( 'webp', 'avif' ), true ) ) {
			return $url;
		}

		// Try AVIF first
		if ( $supports_avif && ! empty( $this->settings['avif_conversion'] ) ) {
			$avif_url  = $this->replace_extension( $url, 'avif' );
			$avif_path = $this->url_to_path( $avif_url );
			if ( file_exists( $avif_path ) ) {
				return $avif_url;
			}
		}

		// Try WebP
		if ( $supports_webp && ! empty( $this->settings['webp_conversion'] ) ) {
			$webp_url  = $this->replace_extension( $url, 'webp' );
			$webp_path = $this->url_to_path( $webp_url );
			if ( file_exists( $webp_path ) ) {
				return $webp_url;
			}
		}

		return $url;
	}

	/**
	 * Replace image extension.
	 *
	 * @param string $url    Image URL.
	 * @param string $format New format.
	 * @return string Modified URL.
	 */
	private function replace_extension( string $url, string $format ): string {
		$path_info = pathinfo( $url );
		$new_url   = $path_info['dirname'] . '/' . $path_info['filename'] . '.' . $format;

		// Adjust for wppo directory
		$new_url = str_replace( WP_CONTENT_URL, WP_CONTENT_URL . '/wppo', $new_url );

		return $new_url;
	}

	/**
	 * Convert URL to file path.
	 *
	 * @param string $url Image URL.
	 * @return string File path.
	 */
	private function url_to_path( string $url ): string {
		$path = str_replace( WP_CONTENT_URL, WP_CONTENT_DIR, $url );
		return wp_normalize_path( $path );
	}

	/**
	 * Check if browser supports AVIF.
	 *
	 * @return bool True if supports AVIF.
	 */
	private function browser_supports_avif(): bool {
		if ( ! isset( $_SERVER['HTTP_ACCEPT'] ) ) {
			return false;
		}

		$http_accept = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) );
		return strpos( $http_accept, 'image/avif' ) !== false;
	}

	/**
	 * Check if browser supports WebP.
	 *
	 * @return bool True if supports WebP.
	 */
	private function browser_supports_webp(): bool {
		if ( ! isset( $_SERVER['HTTP_ACCEPT'] ) ) {
			return false;
		}

		$http_accept = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) );
		return strpos( $http_accept, 'image/webp' ) !== false;
	}
}
