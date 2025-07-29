<?php
/**
 * Frontend Manager for Performance Optimisation.
 *
 * @package PerformanceOptimise
 * @since 2.0.0
 */

namespace PerformanceOptimise\Inc\Refactor;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Frontend_Manager
 *
 * @package PerformanceOptimise\Inc\Refactor
 */
class Frontend_Manager {

	/**
	 * Options for performance optimisation settings.
	 *
	 * @var array<string, mixed>
	 * @since 2.0.0
	 */
	private array $options;

	/**
	 * Image Optimisation instance for handling image optimization.
	 *
	 * @var Image_Optimisation|null
	 * @since 2.0.0
	 */
	private ?Image_Optimisation $image_optimisation = null;

	/**
	 * Frontend_Manager constructor.
	 *
	 * @param array<string, mixed> $options The plugin options.
	 */
	public function __construct( array $options ) {
		$this->options = $options;
		if ( ! empty( $this->options['image_optimisation']['convertImg'] ) || ! empty( $this->options['image_optimisation']['lazyLoadImages'] ) ) {
			$this->image_optimisation = new Image_Optimisation( $this->options );
		}
	}

	/**
	 * Register hooks for frontend functionality.
	 */
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
		add_action( 'wp_head', array( $this, 'add_preload_prefetch_preconnect_links' ), 1 );

		if ( ! empty( $this->options['file_optimisation']['removeWooCSSJS'] ) && (bool) $this->options['file_optimisation']['removeWooCSSJS'] ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'conditionally_remove_woocommerce_assets' ), 999 );
		}
	}

	/**
	 * Enqueues frontend scripts, like lazyload.
	 *
	 * @since 2.0.0
	 */
	public function enqueue_frontend_scripts(): void {
		$lazyload_images_enabled = ! empty( $this->options['image_optimisation']['lazyLoadImages'] ) && (bool) $this->options['image_optimisation']['lazyLoadImages'];
		$lazyload_videos_enabled = ! empty( $this->options['image_optimisation']['lazyLoadVideos'] ) && (bool) $this->options['image_optimisation']['lazyLoadVideos'];

		if ( ( $lazyload_images_enabled || $lazyload_videos_enabled ) && ! is_admin() && ! is_user_logged_in() ) {
			wp_enqueue_script(
				'wppo-lazyload',
				WPPO_PLUGIN_URL . 'assets/js/lazyload.js',
				array(),
				WPPO_VERSION,
				true
			);
		}
	}

	/**
	 * Removes WooCommerce-related scripts and styles on non-WooCommerce pages based on settings.
	 *
	 * @since 2.0.0
	 */
	public function conditionally_remove_woocommerce_assets(): void {
		if ( ! class_exists( 'WooCommerce' ) || is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) {
			return;
		}

		$exclude_urls_from_removal = array();
		if ( ! empty( $this->options['file_optimisation']['excludeUrlToKeepJSCSS'] ) ) {
			$exclude_urls_from_removal = Util::process_urls( (string) $this->options['file_optimisation']['excludeUrlToKeepJSCSS'] );
		}

		$current_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$current_page_url    = home_url( $current_request_uri );
		$current_page_url    = rtrim( $current_page_url, '/' );

		foreach ( $exclude_urls_from_removal as $exclude_url_pattern ) {
			$exclude_url_pattern = rtrim( $exclude_url_pattern, '/' );
			if ( 0 !== strpos( $exclude_url_pattern, 'http' ) ) {
				$exclude_url_pattern = home_url( $exclude_url_pattern );
				$exclude_url_pattern = rtrim( $exclude_url_pattern, '/' );
			}

			if ( str_ends_with( $exclude_url_pattern, '(.*)' ) ) {
				$base_pattern = rtrim( str_replace( '(.*)', '', $exclude_url_pattern ), '/' );
				if ( 0 === strpos( $current_page_url, $base_pattern ) ) {
					return;
				}
			} elseif ( $current_page_url === $exclude_url_pattern ) {
				return;
			}
		}

		$handles_to_remove_config = $this->options['file_optimisation']['removeCssJsHandle'] ?? '';
		$handles_to_remove        = Util::process_urls( (string) $handles_to_remove_config );

		if ( ! empty( $handles_to_remove ) ) {
			foreach ( $handles_to_remove as $handle_directive ) {
				if ( str_starts_with( $handle_directive, 'style:' ) ) {
					$handle = trim( str_replace( 'style:', '', $handle_directive ) );
					wp_dequeue_style( $handle );
				} elseif ( str_starts_with( $handle_directive, 'script:' ) ) {
					$handle = trim( str_replace( 'script:', '', $handle_directive ) );
					wp_dequeue_script( $handle );
				}
			}
		} else {
			$default_woo_handles = array(
				'style:woocommerce-layout',
				'style:woocommerce-smallscreen',
				'style:woocommerce-general',
				'script:wc-cart-fragments',
				'script:woocommerce',
				'script:wc-add-to-cart',
			);
			foreach ( $default_woo_handles as $handle_directive ) {
				if ( str_starts_with( $handle_directive, 'style:' ) ) {
					wp_dequeue_style( trim( str_replace( 'style:', '', $handle_directive ) ) );
				} elseif ( str_starts_with( $handle_directive, 'script:' ) ) {
					wp_dequeue_script( trim( str_replace( 'script:', '', $handle_directive ) ) );
				}
			}
		}
	}

	/**
	 * Adds preload, prefetch, and preconnect links to the <head>.
	 *
	 * @since 2.0.0
	 */
	public function add_preload_prefetch_preconnect_links(): void {
		if ( is_admin() ) {
			return;
		}

		$preload_settings = $this->options['preload_settings'] ?? array();

		if ( ! empty( $preload_settings['preconnect'] ) && ! empty( $preload_settings['preconnectOrigins'] ) ) {
			$origins = Util::process_urls( (string) $preload_settings['preconnectOrigins'] );
			foreach ( $origins as $origin ) {
				if ( filter_var( $origin, FILTER_VALIDATE_URL ) ) {
					Util::generate_preload_link( $origin, 'preconnect', '', true ); // True for crossorigin.
				}
			}
		}

		if ( ! empty( $preload_settings['prefetchDNS'] ) && ! empty( $preload_settings['dnsPrefetchOrigins'] ) ) {
			$origins = Util::process_urls( (string) $preload_settings['dnsPrefetchOrigins'] );
			foreach ( $origins as $origin ) {
				$host = wp_parse_url( $origin, PHP_URL_HOST );
				if ( empty( $host ) && filter_var( 'http://' . $origin, FILTER_VALIDATE_URL ) ) {
					$host = $origin;
				}
				if ( $host ) {
					Util::generate_preload_link( '//' . $host, 'dns-prefetch' );
				}
			}
		}

		if ( ! empty( $preload_settings['preloadFonts'] ) && ! empty( $preload_settings['preloadFontsUrls'] ) ) {
			$font_urls = Util::process_urls( (string) $preload_settings['preloadFontsUrls'] );
			foreach ( $font_urls as $font_url ) {
				$absolute_font_url = $font_url;
				if ( ! preg_match( '/^https?:\/\//i', $font_url ) ) {
					$absolute_font_url = content_url( ltrim( $font_url, '/' ) ); // Assume relative to content dir.
				}
				$font_extension = strtolower( pathinfo( wp_parse_url( $absolute_font_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				$font_mime_type = '';
				switch ( $font_extension ) {
					case 'woff2':
						$font_mime_type = 'font/woff2';
						break;
					case 'woff':
						$font_mime_type = 'font/woff';
						break;
					case 'ttf':
						$font_mime_type = 'font/ttf';
						break;
					case 'otf':
						$font_mime_type = 'font/otf';
						break;
					case 'eot':
						$font_mime_type = 'application/vnd.ms-fontobject';
						break;
				}
				if ( ! empty( $font_mime_type ) ) {
					Util::generate_preload_link( $absolute_font_url, 'preload', 'font', true, $font_mime_type );
				}
			}
		}

		if ( ! empty( $preload_settings['preloadCSS'] ) && ! empty( $preload_settings['preloadCSSUrls'] ) ) {
			$css_urls = Util::process_urls( (string) $preload_settings['preloadCSSUrls'] );
			foreach ( $css_urls as $css_url ) {
				$absolute_css_url = $css_url;
				if ( ! preg_match( '/^https?:\/\//i', $css_url ) ) {
					$absolute_css_url = content_url( ltrim( $css_url, '/' ) );
				}
				Util::generate_preload_link( $absolute_css_url, 'preload', 'style' );
			}
		}

		if ( $this->image_optimisation ) {
			$this->image_optimisation->preload_images_on_page_load();
		}
	}
}
