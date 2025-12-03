<?php
/**
 * Font Optimization Service
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
 * Class FontOptimizationService
 */
class FontOptimizationService {

	/**
	 * Settings service.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	/**
	 * Logger instance.
	 *
	 * @var LoggingUtil
	 */
	private LoggingUtil $logger;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Settings service.
	 * @param LoggingUtil     $logger   Logger instance.
	 */
	public function __construct( SettingsService $settings, LoggingUtil $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;

		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks(): void {
		$settings = $this->settings->get_setting( 'preloading', 'preload_fonts', array() );
		$swap_enabled = $this->settings->get_setting( 'fonts', 'display_swap', false );

		if ( ! empty( $settings ) ) {
			add_action( 'wp_head', array( $this, 'preload_fonts' ), 5 );
		}

		if ( $swap_enabled ) {
			add_filter( 'style_loader_tag', array( $this, 'add_font_display_swap' ), 10, 2 );
			add_filter( 'wp_resource_hints', array( $this, 'add_google_fonts_preconnect' ), 10, 2 );
		}
	}

	/**
	 * Preload critical fonts.
	 */
	public function preload_fonts(): void {
		$fonts = $this->settings->get_setting( 'preloading', 'preload_fonts', array() );

		if ( empty( $fonts ) ) {
			return;
		}

		foreach ( $fonts as $font_url ) {
			$font_url = esc_url( $font_url );
			// Determine mime type based on extension
			$ext = pathinfo( $font_url, PATHINFO_EXTENSION );
			$type = 'font/' . $ext;
			if ( 'ttf' === $ext ) {
				$type = 'font/ttf'; // or application/x-font-ttf
			} elseif ( 'otf' === $ext ) {
				$type = 'font/otf';
			}

			echo "<link rel='preload' href='{$font_url}' as='font' type='{$type}' crossorigin>\n";
		}
	}

	/**
	 * Add font-display: swap to Google Fonts.
	 * 
	 * @param string $html The link tag HTML.
	 * @param string $handle The style handle.
	 * @return string Modified HTML.
	 */
	public function add_font_display_swap( string $html, string $handle ): string {
		if ( strpos( $html, 'fonts.googleapis.com' ) !== false ) {
			if ( strpos( $html, 'display=' ) === false ) {
				return str_replace( 'fonts.googleapis.com/css?', 'fonts.googleapis.com/css?display=swap&', $html );
			}
		}
		return $html;
	}
	
	/**
	 * Add preconnect for Google Fonts.
	 * 
	 * @param array  $urls   URLs to print for resource hints.
	 * @param string $relation_type The relation type the URLs are printed for.
	 * @return array Modified URLs.
	 */
	public function add_google_fonts_preconnect( array $urls, string $relation_type ): array {
		if ( 'preconnect' === $relation_type ) {
			$urls[] = 'https://fonts.gstatic.com';
			$urls[] = 'https://fonts.googleapis.com';
		}
		return $urls;
	}
}
