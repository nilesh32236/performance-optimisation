<?php
/**
 * Conditional Library Loader functionality.
 *
 * Handles selective enqueuing of heavy third-party libraries based on
 * content triggers and page conditions.
 *
 * @package PerformanceOptimise
 * @since   1.4.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Conditional_Loader
 *
 * Manages the conditional enqueuing of libraries like Swiper, GSAP, and AOS.
 *
 * @since 1.4.0
 */
class Conditional_Loader {

	/**
	 * Constructor.
	 *
	 * Registers the enqueuing logic on the wp_enqueue_scripts hook.
	 *
	 * @since 1.4.0
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_libraries' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_conditional_libraries' ), 20 );
	}

	/**
	 * Register all third-party libraries so they can be enqueued by handle.
	 *
	 * @since 1.4.0
	 */
	public function register_libraries() {
		$plugin_url = WPPO_PLUGIN_URL;
		$version    = WPPO_VERSION;

		// AOS (Animate On Scroll)
		wp_register_style( 'aos-style', $plugin_url . 'assets/library/aos.css', array(), $version, 'all' );
		wp_register_script( 'aos-js', $plugin_url . 'assets/library/aos.js', array( 'jquery' ), $version, true );

		// Swiper
		wp_register_style( 'swiper-bundle-css', $plugin_url . 'assets/library/swiper-bundle.min.css', array(), $version, 'all' );
		wp_register_script( 'swiper-bundle-js', $plugin_url . 'assets/library/swiper-bundle.min.js', array(), $version, true );

		// GSAP
		wp_register_script( 'gsap-js', $plugin_url . 'assets/library/gsap.min.js', array(), $version, true );
		wp_register_script( 'gsap-scroll-trigger', $plugin_url . 'assets/library/ScrollTrigger.min.js', array( 'gsap-js' ), $version, true );

		// Magnific Popup
		wp_register_style( 'magnific-popup-css', $plugin_url . 'assets/library/magnific-popup.css', array(), $version, 'all' );
		wp_register_script( 'magnific-popup-js', $plugin_url . 'assets/library/jquery.magnific-popup.min.js', array( 'jquery' ), $version, true );

		// Lenis (Smooth Scroll)
		wp_register_script( 'lenis-js', $plugin_url . 'assets/library/lenis.min.js', array(), $version, true );
	}

	/**
	 * Selective enqueuing based on page type, block presence, and content triggers.
	 *
	 * @since 1.4.0
	 */
	public function enqueue_conditional_libraries() {
		if ( is_admin() ) {
			return;
		}

		$post_id = get_the_ID();
		$content = '';

		if ( $post_id ) {
			$post    = get_post( $post_id );
			$content = $post ? $post->post_content : '';
		}

		// 1. Swiper Condition
		$this->handle_swiper( $post_id, $content );

		// 2. GSAP Condition
		$this->handle_gsap( $post_id, $content );

		// 3. AOS Condition
		$this->handle_aos( $post_id, $content );

		// 4. Magnific Popup Condition
		$this->handle_magnific_popup( $post_id, $content );

		// 5. Lenis (Global but can be restricted)
		wp_enqueue_script( 'lenis-js' );
	}

	/**
	 * Handle Swiper enqueue logic.
	 */
	private function handle_swiper( $post_id, $content ) {
		$swiper_blocks = array(
			'create-block/work-with-v3',
			'create-block/our-works-v3',
			'create-block/testimonials-v3',
			'create-block/casestudy-related-section-v2',
			'create-block/image-slider-section-v2',
			'create-block/brand-logo',
			'create-block/our-journey-v2',
		);

		$needs_swiper = false;
		if ( is_front_page() || is_singular( 'services' ) || is_page_template( 'page-templates/services.php' ) || is_tax( 'industries' ) || is_tax( 'location' ) ) {
			$needs_swiper = true;
		} else {
			foreach ( $swiper_blocks as $block ) {
				if ( has_block( $block, $post_id ) ) {
					$needs_swiper = true;
					break;
				}
			}

			if ( ! $needs_swiper && ( 
				strpos( $content, 'jy-testimonial' ) !== false || 
				strpos( $content, 'our-working-philosophy' ) !== false || 
				strpos( $content, 'casestudy-related' ) !== false ||
				strpos( $content, 'swiper' ) !== false
			) ) {
				$needs_swiper = true;
			}
		}

		if ( $needs_swiper ) {
			wp_enqueue_script( 'swiper-bundle-js' );
			wp_enqueue_style( 'swiper-bundle-css' );
		}
	}

	/**
	 * Handle GSAP enqueue logic.
	 */
	private function handle_gsap( $post_id, $content ) {
		$needs_gsap = false;
		if ( is_front_page() || is_singular( 'case-studies' ) ) {
			$needs_gsap = true;
		} else {
			if ( strpos( $content, 'casestudy-hero-video' ) !== false || strpos( $content, 'case-study-gallery' ) !== false ) {
				$needs_gsap = true;
			}
		}

		if ( $needs_gsap ) {
			wp_enqueue_script( 'gsap-js' );
			wp_enqueue_script( 'gsap-scroll-trigger' );
		}
	}

	/**
	 * Handle AOS enqueue logic.
	 */
	private function handle_aos( $post_id, $content ) {
		if ( strpos( $content, 'data-aos' ) !== false || is_front_page() ) {
			wp_enqueue_script( 'aos-js' );
			wp_enqueue_style( 'aos-style' );
		}
	}

	/**
	 * Handle Magnific Popup enqueue logic.
	 */
	private function handle_magnific_popup( $post_id, $content ) {
		if ( strpos( $content, 'magnific-popup' ) !== false || strpos( $content, 'casestudy-gallery' ) !== false ) {
			wp_enqueue_script( 'magnific-popup-js' );
			wp_enqueue_style( 'magnific-popup-css' );
		}
	}
}
