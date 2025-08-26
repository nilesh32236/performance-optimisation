<?php
/**
 * Frontend Class
 *
 * @package PerformanceOptimisation\Frontend
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Frontend;

use PerformanceOptimisation\Services\CacheService;
use PerformanceOptimisation\Services\ImageService;
use PerformanceOptimisation\Services\OptimizationService;
use PerformanceOptimisation\Services\SettingsService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Frontend
 *
 * @package PerformanceOptimisation\Frontend
 */
class Frontend {

	private CacheService $cacheService;
	private ImageService $imageService;
	private OptimizationService $optimizationService;
	private SettingsService $settingsService;

	public function __construct(
		CacheService $cacheService,
		ImageService $imageService,
		OptimizationService $optimizationService,
		SettingsService $settingsService
	) {
		$this->cacheService        = $cacheService;
		$this->imageService        = $imageService;
		$this->optimizationService = $optimizationService;
		$this->settingsService     = $settingsService;
	}

	public function setup_hooks(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_scripts' ] );
		add_action( 'wp_head', [ $this, 'add_preload_prefetch_preconnect_links' ], 1 );
		add_filter( 'script_loader_tag', [ $this, 'modify_script_loader_tag' ], 20, 3 );
		add_filter( 'style_loader_tag', [ $this, 'modify_style_loader_tag' ], 20, 3 );

		if ( $this->settingsService->get_setting( 'file_optimisation', 'removeWooCSSJS' ) ) {
			add_action( 'wp_enqueue_scripts', [ $this, 'conditionally_remove_woocommerce_assets' ], 999 );
		}

		if ( $this->settingsService->get_setting( 'preload_settings', 'enablePreloadCache' ) ) {
			add_action( 'template_redirect', [ $this->cacheService, 'generate_dynamic_static_html' ], 5 );
		}

		if ( $this->settingsService->get_setting( 'file_optimisation', 'combineCSS' ) ) {
			add_action( 'wp_print_styles', [ $this->optimizationService, 'combine_css' ], PHP_INT_MAX - 10 );
		}
	}

	public function enqueue_frontend_scripts(): void {
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}

		if ( $this->settingsService->get_setting( 'image_optimisation', 'lazy_loading' ) ) {
			wp_enqueue_script(
				'wppo-lazyload',
				WPPO_PLUGIN_URL . 'assets/js/lazyload.js',
				[],
				WPPO_VERSION,
				true
			);
		}
	}

	public function add_preload_prefetch_preconnect_links(): void {
		if ( is_admin() ) {
			return;
		}

		$settings   = $this->settingsService->get_setting( 'preload_settings', '' );
		$link_types = [
			'preconnect'
			=> 'preconnectOrigins',
			'dns-prefetch'
			=> 'dnsPrefetchOrigins',
			'preload-font'
			=> 'preloadFontsUrls',
			'preload-css'
			=> 'preloadCSSUrls',
		];

		foreach ( $link_types as $rel => $setting_key ) {
			if ( ! empty( $settings[ $setting_key ] ) ) {
				$urls = explode( "\n", $settings[ $setting_key ] );
				foreach ( $urls as $url ) {
					echo '<link rel="' . esc_attr( $rel ) . '" href="' . esc_url( trim( $url ) ) . '" crossorigin>';
				}
			}
		}
	}

	public function modify_script_loader_tag( string $tag, string $handle, string $src ): string {
		if ( is_user_logged_in() || is_admin() || empty( $src ) ) {
			return $tag;
		}

		$should_defer = $this->settingsService->get_setting( 'file_optimisation', 'deferJs' );
		$should_delay = $this->settingsService->get_setting( 'file_optimisation', 'delayJs' );

		if ( $should_delay ) {
			$tag = str_replace( ' src=', ' data-wppo-src=', $tag );
			$tag = str_replace( ' type=', ' data-wppo-type=', $tag );
		} elseif ( $should_defer ) {
			$tag = str_replace( ' src=', ' defer src=', $tag );
		}

		return $tag;
	}

	public function modify_style_loader_tag( string $tag, string $handle, string $href ): string {
		if ( is_user_logged_in() || is_admin() || empty( $href ) ) {
			return $tag;
		}

		return $tag;
	}

	public function conditionally_remove_woocommerce_assets(): void {
		if ( ! class_exists( 'WooCommerce' ) || is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) {
			return;
		}

		$styles_to_remove  = [ 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-general' ];
		$scripts_to_remove = [ 'wc-cart-fragments', 'woocommerce', 'wc-add-to-cart' ];

		foreach ( $styles_to_remove as $handle ) {
			wp_dequeue_style( $handle );
		}
		foreach ( $scripts_to_remove as $handle ) {
			wp_dequeue_script( $handle );
		}
	}
}
