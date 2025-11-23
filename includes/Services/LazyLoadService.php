<?php
/**
 * Lazy Load Service
 *
 * @package PerformanceOptimisation\Services
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LazyLoadService
 */
class LazyLoadService {

	private array $settings;
	private array $exclude_images = array();

	public function __construct( array $settings ) {
		$this->settings = $settings;

		if ( ! empty( $settings['exclude_images'] ) ) {
			$this->exclude_images = array_map( 'trim', explode( "\n", $settings['exclude_images'] ) );
		}
	}

	/**
	 * Initialize lazy loading hooks.
	 */
	public function init(): void {
		if ( empty( $this->settings['lazy_load_enabled'] ) ) {
			return;
		}

		add_filter( 'the_content', array( $this, 'add_lazy_loading' ), 999 );
		add_filter( 'post_thumbnail_html', array( $this, 'add_lazy_loading' ), 999 );
		add_filter( 'get_avatar', array( $this, 'add_lazy_loading' ), 999 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue lazy loading scripts.
	 */
	public function enqueue_scripts(): void {
		wp_enqueue_script(
			'wppo-lazyload',
			plugins_url( 'assets/js/lazyload.js', WPPO_PLUGIN_FILE ),
			array(),
			WPPO_VERSION,
			true
		);
	}

	/**
	 * Add lazy loading to images and iframes.
	 *
	 * @param string $content HTML content.
	 * @return string Modified content.
	 */
	public function add_lazy_loading( string $content ): string {
		if ( is_admin() || is_feed() || wp_doing_ajax() ) {
			return $content;
		}

		$exclude_count = $this->settings['exclude_first_images'] ?? 0;
		$img_counter   = 0;

		// Process images
		$content = preg_replace_callback(
			'/<img([^>]+)>/i',
			function ( $matches ) use ( &$img_counter, $exclude_count ) {
				++$img_counter;

				$img_tag = $matches[0];

				// Skip if already has data-src
				if ( strpos( $img_tag, 'data-src' ) !== false ) {
					return $img_tag;
				}

				// Skip first N images
				if ( $img_counter <= $exclude_count ) {
					return $img_tag;
				}

				// Check exclusions
				foreach ( $this->exclude_images as $exclude ) {
					if ( ! empty( $exclude ) && strpos( $img_tag, $exclude ) !== false ) {
						return $img_tag;
					}
				}

				// Extract src
				if ( preg_match( '/src=["\']([^"\']+)["\']/i', $img_tag, $src_match ) ) {
					$src = $src_match[1];

					// Replace src with data-src
					$img_tag = str_replace( $src_match[0], 'data-src="' . esc_attr( $src ) . '"', $img_tag );

					// Add placeholder
					if ( ! empty( $this->settings['use_svg_placeholder'] ) ) {
						$placeholder = $this->generate_svg_placeholder( $img_tag );
						$img_tag     = preg_replace( '/<img/', '<img src="' . $placeholder . '"', $img_tag, 1 );
					} else {
						$img_tag = preg_replace( '/<img/', '<img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"', $img_tag, 1 );
					}

					// Handle srcset
					if ( preg_match( '/srcset=["\']([^"\']+)["\']/i', $img_tag, $srcset_match ) ) {
						$img_tag = str_replace( $srcset_match[0], 'data-srcset="' . esc_attr( $srcset_match[1] ) . '"', $img_tag );
					}

					// Add loading class
					if ( strpos( $img_tag, 'class=' ) !== false ) {
						$img_tag = preg_replace( '/class=["\']([^"\']*)["\']/', 'class="$1 lazyload"', $img_tag );
					} else {
						$img_tag = str_replace( '<img', '<img class="lazyload"', $img_tag );
					}
				}

				return $img_tag;
			},
			$content
		);

		// Process iframes
		$content = preg_replace_callback(
			'/<iframe([^>]+)>/i',
			function ( $matches ) {
				$iframe_tag = $matches[0];

				// Skip if already has data-src
				if ( strpos( $iframe_tag, 'data-src' ) !== false ) {
					return $iframe_tag;
				}

				// Check exclusions
				foreach ( $this->exclude_images as $exclude ) {
					if ( ! empty( $exclude ) && strpos( $iframe_tag, $exclude ) !== false ) {
						return $iframe_tag;
					}
				}

				// Extract src
				if ( preg_match( '/src=["\']([^"\']+)["\']/i', $iframe_tag, $src_match ) ) {
					$iframe_tag = str_replace( $src_match[0], 'data-src="' . esc_attr( $src_match[1] ) . '"', $iframe_tag );
				}

				return $iframe_tag;
			},
			$content
		);

		return $content;
	}

	/**
	 * Generate SVG placeholder.
	 *
	 * @param string $img_tag Image tag.
	 * @return string SVG data URL.
	 */
	private function generate_svg_placeholder( string $img_tag ): string {
		$width  = 100;
		$height = 100;

		if ( preg_match( '/width=["\'](\d+)["\']/i', $img_tag, $w_match ) ) {
			$width = $w_match[1];
		}

		if ( preg_match( '/height=["\'](\d+)["\']/i', $img_tag, $h_match ) ) {
			$height = $h_match[1];
		}

		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '"><rect width="100%" height="100%" fill="#f0f0f0"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
