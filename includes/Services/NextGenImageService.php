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
	private $conversionQueue;

	public function __construct( array $settings, $conversionQueue = null ) {
		$this->settings        = $settings;
		$this->conversionQueue = $conversionQueue;

		if ( ! empty( $settings['images']['exclude_webp_images'] ) ) {
			$this->exclude_images = array_map( 'trim', explode( "\n", $settings['images']['exclude_webp_images'] ) );
		}
	}

	/**
	 * Initialize next-gen image serving.
	 */
	public function init(): void {
		// Check both new and old settings structures for WebP/AVIF enabled
		$webp_enabled = ! empty( $this->settings['images']['convert_to_webp'] )
			|| ! empty( $this->settings['image_optimization']['webp_conversion'] );
		$avif_enabled = ! empty( $this->settings['images']['convert_to_avif'] )
			|| ! empty( $this->settings['image_optimization']['avif_conversion'] );

		if ( ! $webp_enabled && ! $avif_enabled ) {
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
									list($url, $descriptor) = array_pad( explode( ' ', trim( $srcset_item ), 2 ), 2, '' );
									$new_url                = $this->get_next_gen_url( $url, $supports_avif, $supports_webp );
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

		// Process CSS background images in inline styles
		$content = $this->process_css_backgrounds( $content, $supports_avif, $supports_webp );

		return $content;
	}

	/**
	 * Maybe serve next-gen image for attachment.
	 *
	 * @param mixed $image Image source array or false.
	 * @return mixed Modified image source or original value.
	 */
	public function maybe_serve_next_gen_image( $image ) {
		// Return early if not an array (e.g., false from wp_get_attachment_image_src)
		if ( ! is_array( $image ) || empty( $image[0] ) ) {
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
		$avif_enabled = ! empty( $this->settings['images']['convert_to_avif'] )
			|| ! empty( $this->settings['image_optimization']['avif_conversion'] );

		if ( $supports_avif && $avif_enabled ) {
			$avif_url  = $this->replace_extension( $url, 'avif' );
			$avif_path = $this->url_to_path( $avif_url );
			if ( file_exists( $avif_path ) ) {
				return $avif_url;
			} else {
				// Queue for conversion if original exists
				$this->queue_if_needed( $url, 'avif' );
			}
		}

		// Try WebP
		$webp_enabled = ! empty( $this->settings['images']['convert_to_webp'] )
			|| ! empty( $this->settings['image_optimization']['webp_conversion'] );

		if ( $supports_webp && $webp_enabled ) {
			$webp_url  = $this->replace_extension( $url, 'webp' );
			$webp_path = $this->url_to_path( $webp_url );
			if ( file_exists( $webp_path ) ) {
				return $webp_url;
			} else {
				// Queue for conversion if original exists
				$this->queue_if_needed( $url, 'webp' );
			}
		}

		return $url;
	}

	/**
	 * Process CSS background images in inline styles
	 *
	 * @param string $content HTML content.
	 * @param bool   $supports_avif Browser supports AVIF.
	 * @param bool   $supports_webp Browser supports WebP.
	 * @return string Modified content.
	 */
	private function process_css_backgrounds( string $content, bool $supports_avif, bool $supports_webp ): string {
		// Pattern to match style attributes containing background images
		$pattern = '/style\s*=\s*["\']([^"\']*(?:background-image|background)\s*:[^"\']*)["\']/' . 'i';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $supports_avif, $supports_webp ) {
				$style = $matches[1];

				// Extract URLs from background-image and background properties
				$url_pattern = '/url\s*\(\s*["\']?([^"\')]+)["\']?\s*\)/i';

				$style = preg_replace_callback(
					$url_pattern,
					function ( $url_match ) use ( $supports_avif, $supports_webp ) {
						$url = $url_match[1];

						// Skip data URIs, gradients, and external URLs
						if ( $this->should_skip_css_url( $url ) ) {
							return $url_match[0];
						}

						// Get next-gen URL
						$new_url = $this->get_next_gen_url( $url, $supports_avif, $supports_webp );

						// Return with preserved format
						$quote = '';
						if ( strpos( $url_match[0], '"' ) !== false ) {
							$quote = '"';
						} elseif ( strpos( $url_match[0], "'" ) !== false ) {
							$quote = "'";
						}

						return 'url(' . $quote . $new_url . $quote . ')';
					},
					$style
				);

				return 'style="' . $style . '"';
			},
			$content
		);
	}

	/**
	 * Check if CSS URL should be skipped
	 *
	 * @param string $url URL to check.
	 * @return bool True if should skip.
	 */
	private function should_skip_css_url( string $url ): bool {
		// Skip data URIs
		if ( strpos( $url, 'data:' ) === 0 ) {
			return true;
		}

		// Skip gradients
		if ( preg_match( '/(linear|radial|repeating)-gradient/i', $url ) ) {
			return true;
		}

		// Skip external URLs (only process same-domain images)
		$site_url = home_url();
		if ( strpos( $url, 'http' ) === 0 && strpos( $url, $site_url ) !== 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * Queue image for conversion if needed.
	 *
	 * @param string $url    Original image URL.
	 * @param string $format Target format (webp/avif).
	 * @return void
	 */
	private function queue_if_needed( string $url, string $format ): void {
		// Skip if no queue available
		if ( ! $this->conversionQueue ) {
			return;
		}

		// Convert URL to file path
		$original_path = $this->url_to_path( $url );

		// Only queue if original file exists
		if ( ! file_exists( $original_path ) ) {
			return;
		}

		// Add to queue and save
		$this->conversionQueue->add( $original_path, $format );
		$this->conversionQueue->save();
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
