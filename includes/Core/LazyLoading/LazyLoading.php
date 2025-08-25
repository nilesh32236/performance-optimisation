<?php
/**
 * Lazy Loading Implementation
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\LazyLoading;

use PerformanceOptimisation\Interfaces\LazyLoadingInterface;
use PerformanceOptimisation\Interfaces\ConfigInterface;

/**
 * Lazy loading implementation
 *
 * @since 1.1.0
 */
class LazyLoading implements LazyLoadingInterface {

	/**
	 * Configuration manager
	 *
	 * @since 1.1.0
	 * @var ConfigInterface
	 */
	private ConfigInterface $config;

	/**
	 * Lazy loading configuration
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private array $lazy_config = array(
		'images'       => true,
		'iframes'      => true,
		'videos'       => true,
		'skip_classes' => array( 'no-lazy', 'skip-lazy' ),
		'threshold'    => '200px',
		'placeholder'  => 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"%3E%3C/svg%3E',
	);

	/**
	 * Processing statistics
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private array $stats = array(
		'images_processed'  => 0,
		'iframes_processed' => 0,
		'videos_processed'  => 0,
		'processing_time'   => 0,
	);

	/**
	 * Constructor
	 *
	 * @since 1.1.0
	 * @param ConfigInterface $config Configuration manager
	 */
	public function __construct( ConfigInterface $config ) {
		$this->config = $config;
	}

	/**
	 * Initialize lazy loading
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function initialize(): void {
		if ( ! $this->config->get( 'images.lazy_loading', true ) ) {
			return;
		}

		// Hook into WordPress content filters
		add_filter( 'the_content', array( $this, 'process_content' ), 999 );
		add_filter( 'post_thumbnail_html', array( $this, 'process_content' ), 999 );
		add_filter( 'get_avatar', array( $this, 'process_content' ), 999 );
		add_filter( 'widget_text', array( $this, 'process_content' ), 999 );

		// Enqueue lazy loading script
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Add noscript fallback
		add_action( 'wp_footer', array( $this, 'add_noscript_styles' ) );
	}

	/**
	 * Process HTML content for lazy loading
	 *
	 * @since 1.1.0
	 * @param string $content HTML content
	 * @return string Processed HTML content
	 */
	public function process_content( string $content ): string {
		if ( empty( $content ) || is_admin() || is_feed() ) {
			return $content;
		}

		$start_time = microtime( true );

		// Process images
		if ( $this->is_enabled_for_type( 'images' ) ) {
			$content = $this->process_images( $content );
		}

		// Process iframes
		if ( $this->is_enabled_for_type( 'iframes' ) ) {
			$content = $this->process_iframes( $content );
		}

		// Process videos
		if ( $this->is_enabled_for_type( 'videos' ) ) {
			$content = $this->process_videos( $content );
		}

		$this->stats['processing_time'] += ( microtime( true ) - $start_time );

		return $content;
	}

	/**
	 * Add lazy loading attributes to an element
	 *
	 * @since 1.1.0
	 * @param string $element_html Element HTML
	 * @param string $element_type Element type (img, iframe, video)
	 * @return string Modified element HTML
	 */
	public function add_lazy_attributes( string $element_html, string $element_type ): string {
		// Check if element should be skipped
		if ( $this->should_skip_element( $element_html ) ) {
			return $element_html;
		}

		switch ( $element_type ) {
			case 'img':
				return $this->add_lazy_attributes_to_image( $element_html );
			case 'iframe':
				return $this->add_lazy_attributes_to_iframe( $element_html );
			case 'video':
				return $this->add_lazy_attributes_to_video( $element_html );
			default:
				return $element_html;
		}
	}

	/**
	 * Check if lazy loading is enabled for element type
	 *
	 * @since 1.1.0
	 * @param string $element_type Element type
	 * @return bool True if enabled, false otherwise
	 */
	public function is_enabled_for_type( string $element_type ): bool {
		return $this->lazy_config[ $element_type ] ?? false;
	}

	/**
	 * Get lazy loading configuration
	 *
	 * @since 1.1.0
	 * @return array Configuration array
	 */
	public function get_config(): array {
		return $this->lazy_config;
	}

	/**
	 * Set lazy loading configuration
	 *
	 * @since 1.1.0
	 * @param array $config Configuration array
	 * @return void
	 */
	public function set_config( array $config ): void {
		$this->lazy_config = array_merge( $this->lazy_config, $config );
	}

	/**
	 * Get lazy loading statistics
	 *
	 * @since 1.1.0
	 * @return array Statistics array
	 */
	public function get_stats(): array {
		return $this->stats;
	}

	/**
	 * Reset lazy loading statistics
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function reset_stats(): void {
		$this->stats = array(
			'images_processed'  => 0,
			'iframes_processed' => 0,
			'videos_processed'  => 0,
			'processing_time'   => 0,
		);
	}

	/**
	 * Enqueue lazy loading scripts
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function enqueue_scripts(): void {
		wp_enqueue_script(
			'wppo-lazy-loading',
			plugin_dir_url( dirname( dirname( __DIR__ ) ) ) . 'assets/js/lazy-loading.js',
			array(),
			'1.1.0',
			true
		);

		// Pass configuration to JavaScript
		wp_localize_script(
			'wppo-lazy-loading',
			'wppoLazyConfig',
			array(
				'threshold'   => $this->lazy_config['threshold'],
				'placeholder' => $this->lazy_config['placeholder'],
			)
		);
	}

	/**
	 * Add noscript styles for fallback
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function add_noscript_styles(): void {
		echo '<noscript><style>.wppo-lazy { display: none !important; }</style></noscript>';
	}

	/**
	 * Process images in content
	 *
	 * @since 1.1.0
	 * @param string $content HTML content
	 * @return string Processed content
	 */
	private function process_images( string $content ): string {
		$pattern = '/<img[^>]*>/i';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$img_html  = $matches[0];
				$processed = $this->add_lazy_attributes_to_image( $img_html );

				if ( $processed !== $img_html ) {
					$this->stats['images_processed']++;
				}

				return $processed;
			},
			$content
		);
	}

	/**
	 * Process iframes in content
	 *
	 * @since 1.1.0
	 * @param string $content HTML content
	 * @return string Processed content
	 */
	private function process_iframes( string $content ): string {
		$pattern = '/<iframe[^>]*>/i';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$iframe_html = $matches[0];
				$processed   = $this->add_lazy_attributes_to_iframe( $iframe_html );

				if ( $processed !== $iframe_html ) {
					$this->stats['iframes_processed']++;
				}

				return $processed;
			},
			$content
		);
	}

	/**
	 * Process videos in content
	 *
	 * @since 1.1.0
	 * @param string $content HTML content
	 * @return string Processed content
	 */
	private function process_videos( string $content ): string {
		$pattern = '/<video[^>]*>/i';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$video_html = $matches[0];
				$processed  = $this->add_lazy_attributes_to_video( $video_html );

				if ( $processed !== $video_html ) {
					$this->stats['videos_processed']++;
				}

				return $processed;
			},
			$content
		);
	}

	/**
	 * Add lazy loading attributes to image
	 *
	 * @since 1.1.0
	 * @param string $img_html Image HTML
	 * @return string Modified image HTML
	 */
	private function add_lazy_attributes_to_image( string $img_html ): string {
		if ( $this->should_skip_element( $img_html ) ) {
			return $img_html;
		}

		// Check if already has lazy loading
		if ( strpos( $img_html, 'loading=' ) !== false || strpos( $img_html, 'data-src=' ) !== false ) {
			return $img_html;
		}

		// Extract src attribute
		if ( ! preg_match( '/src=["\']([^"\']*)["\']/', $img_html, $src_matches ) ) {
			return $img_html;
		}

		$original_src = $src_matches[1];

		// Replace src with data-src and add placeholder
		$img_html = str_replace( $src_matches[0], 'data-src="' . $original_src . '"', $img_html );
		$img_html = str_replace( '<img', '<img src="' . $this->lazy_config['placeholder'] . '"', $img_html );

		// Add lazy loading class
		if ( strpos( $img_html, 'class=' ) !== false ) {
			$img_html = preg_replace( '/class=["\']([^"\']*)["\']/', 'class="$1 wppo-lazy"', $img_html );
		} else {
			$img_html = str_replace( '<img', '<img class="wppo-lazy"', $img_html );
		}

		// Add native lazy loading as fallback
		$img_html = str_replace( '<img', '<img loading="lazy"', $img_html );

		return $img_html;
	}

	/**
	 * Add lazy loading attributes to iframe
	 *
	 * @since 1.1.0
	 * @param string $iframe_html Iframe HTML
	 * @return string Modified iframe HTML
	 */
	private function add_lazy_attributes_to_iframe( string $iframe_html ): string {
		if ( $this->should_skip_element( $iframe_html ) ) {
			return $iframe_html;
		}

		// Check if already has lazy loading
		if ( strpos( $iframe_html, 'loading=' ) !== false || strpos( $iframe_html, 'data-src=' ) !== false ) {
			return $iframe_html;
		}

		// Extract src attribute
		if ( ! preg_match( '/src=["\']([^"\']*)["\']/', $iframe_html, $src_matches ) ) {
			return $iframe_html;
		}

		$original_src = $src_matches[1];

		// Replace src with data-src
		$iframe_html = str_replace( $src_matches[0], 'data-src="' . $original_src . '"', $iframe_html );

		// Add lazy loading class
		if ( strpos( $iframe_html, 'class=' ) !== false ) {
			$iframe_html = preg_replace( '/class=["\']([^"\']*)["\']/', 'class="$1 wppo-lazy"', $iframe_html );
		} else {
			$iframe_html = str_replace( '<iframe', '<iframe class="wppo-lazy"', $iframe_html );
		}

		// Add native lazy loading as fallback
		$iframe_html = str_replace( '<iframe', '<iframe loading="lazy"', $iframe_html );

		return $iframe_html;
	}

	/**
	 * Add lazy loading attributes to video
	 *
	 * @since 1.1.0
	 * @param string $video_html Video HTML
	 * @return string Modified video HTML
	 */
	private function add_lazy_attributes_to_video( string $video_html ): string {
		if ( $this->should_skip_element( $video_html ) ) {
			return $video_html;
		}

		// Check if already has lazy loading
		if ( strpos( $video_html, 'data-src=' ) !== false ) {
			return $video_html;
		}

		// Remove autoplay to prevent loading
		$video_html = preg_replace( '/\s*autoplay[^>\s]*/', '', $video_html );

		// Add lazy loading class
		if ( strpos( $video_html, 'class=' ) !== false ) {
			$video_html = preg_replace( '/class=["\']([^"\']*)["\']/', 'class="$1 wppo-lazy"', $video_html );
		} else {
			$video_html = str_replace( '<video', '<video class="wppo-lazy"', $video_html );
		}

		// Add preload="none" to prevent loading
		if ( strpos( $video_html, 'preload=' ) === false ) {
			$video_html = str_replace( '<video', '<video preload="none"', $video_html );
		}

		return $video_html;
	}

	/**
	 * Check if element should be skipped
	 *
	 * @since 1.1.0
	 * @param string $element_html Element HTML
	 * @return bool True if should be skipped, false otherwise
	 */
	private function should_skip_element( string $element_html ): bool {
		// Skip if element has skip classes
		foreach ( $this->lazy_config['skip_classes'] as $skip_class ) {
			if ( strpos( $element_html, $skip_class ) !== false ) {
				return true;
			}
		}

		// Skip if element has data-no-lazy attribute
		if ( strpos( $element_html, 'data-no-lazy' ) !== false ) {
			return true;
		}

		// Skip if element is in noscript tag
		if ( strpos( $element_html, '<noscript' ) !== false ) {
			return true;
		}

		return false;
	}
}
