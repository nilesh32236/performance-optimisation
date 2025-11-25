<?php
/**
 * Asset Optimization Service.
 *
 * Handles WordPress hooks for asset optimization (CSS, JS, HTML).
 *
 * @package PerformanceOptimisation\Services
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Optimizers\CssOptimizer;
use PerformanceOptimisation\Optimizers\JsOptimizer;
use PerformanceOptimisation\Optimizers\HtmlOptimizer;
use PerformanceOptimisation\Utils\FileSystemUtil;
use PerformanceOptimisation\Utils\LoggingUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AssetOptimizationService
 */
class AssetOptimizationService {

	/**
	 * Settings service.
	 *
	 * @var SettingsService
	 */
	private $settings_service;

	/**
	 * CSS Optimizer.
	 *
	 * @var CssOptimizer
	 */
	private $css_optimizer;

	/**
	 * JS Optimizer.
	 *
	 * @var JsOptimizer
	 */
	private $js_optimizer;

	/**
	 * HTML Optimizer.
	 *
	 * @var HtmlOptimizer
	 */
	private $html_optimizer;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings_service Settings service.
	 * @param CssOptimizer    $css_optimizer    CSS Optimizer.
	 * @param JsOptimizer     $js_optimizer     JS Optimizer.
	 * @param HtmlOptimizer   $html_optimizer   HTML Optimizer.
	 */
	public function __construct(
		SettingsService $settings_service,
		CssOptimizer $css_optimizer,
		JsOptimizer $js_optimizer,
		HtmlOptimizer $html_optimizer
	) {
		$this->settings_service = $settings_service;
		$this->css_optimizer    = $css_optimizer;
		$this->js_optimizer     = $js_optimizer;
		$this->html_optimizer   = $html_optimizer;
	}

	/**
	 * Initialize hooks.
	 */
	public function init(): void {
		// CSS Optimization
		if ( $this->settings_service->get_setting( 'minification', 'minify_css' ) ) {
			add_filter( 'style_loader_tag', array( $this, 'optimize_css_tag' ), 10, 4 );
		}

		// JS Optimization
		if ( $this->settings_service->get_setting( 'minification', 'minify_js' ) ) {
			add_filter( 'script_loader_tag', array( $this, 'optimize_js_tag' ), 10, 3 );
		}

		// HTML Optimization
		if ( $this->settings_service->get_setting( 'minification', 'minify_html' ) ) {
			add_action( 'template_redirect', array( $this, 'start_html_buffer' ), 0 );
		}
	}

	/**
	 * Optimize CSS tag.
	 *
	 * @param string $tag    The link tag for the enqueued style.
	 * @param string $handle The style's registered handle.
	 * @param string $href   The stylesheet's source URL.
	 * @param string $media  The stylesheet's media attribute.
	 * @return string Optimized tag.
	 */
	public function optimize_css_tag( string $tag, string $handle, string $href, string $media ): string {
		if ( is_admin() || is_user_logged_in() ) {
			return $tag;
		}

		// Check exclusions
		$excluded = $this->settings_service->get_setting( 'minification', 'exclude_css', array() );
		if ( in_array( $handle, $excluded, true ) ) {
			return $tag;
		}

		// Get local path
		$local_path = FileSystemUtil::getLocalPath( $href );
		if ( ! $local_path || ! file_exists( $local_path ) ) {
			return $tag;
		}

		// Optimize file
		$result = $this->css_optimizer->optimizeFile( $local_path, array( 'save_optimized' => true ) );

		if ( $result['success'] && ! empty( $result['optimized_path'] ) ) {
			$optimized_url = FileSystemUtil::getUrlFromPath( $result['optimized_path'] );
			if ( $optimized_url ) {
				$tag = str_replace( $href, $optimized_url, $tag );
			}
		}

		return $tag;
	}

	/**
	 * Optimize JS tag.
	 *
	 * @param string $tag    The script tag for the enqueued script.
	 * @param string $handle The script's registered handle.
	 * @param string $src    The script's source URL.
	 * @return string Optimized tag.
	 */
	public function optimize_js_tag( string $tag, string $handle, string $src ): string {
		if ( is_admin() || is_user_logged_in() ) {
			return $tag;
		}

		// Check exclusions
		$excluded = $this->settings_service->get_setting( 'minification', 'exclude_js', array() );
		if ( in_array( $handle, $excluded, true ) ) {
			return $tag;
		}

		// Defer/Delay logic
		$defer = $this->settings_service->get_setting( 'minification', 'defer_js' );
		$delay = $this->settings_service->get_setting( 'minification', 'delay_js' );

		if ( $defer ) {
			$tag = str_replace( ' src', ' defer="defer" src', $tag );
		}

		if ( $delay ) {
			// Simple delay implementation
			$tag = str_replace( ' src', ' data-src', $tag );
			$tag = str_replace( '<script ', '<script type="wppo/javascript" ', $tag );
		}

		// Minification
		$local_path = FileSystemUtil::getLocalPath( $src );
		if ( $local_path && file_exists( $local_path ) ) {
			// Check if already minified
			if ( strpos( $local_path, '.min.js' ) === false ) {
				// Process file (this method handles caching internally)
				$optimized_content = $this->js_optimizer->process_file( $local_path );
				
				// In a real scenario, we would save this to a file and serve that URL
				// For now, let's assume process_file returns content, but we need a URL
				// We need to implement file saving in JsOptimizer or here.
				// JsOptimizer::process_file returns content.
				
				// Let's generate a cache file path
				$filename = basename( $local_path, '.js' ) . '.min.js';
				$cache_dir = WP_CONTENT_DIR . '/cache/wppo/min/js/';
				$cache_path = $cache_dir . $filename;
				
				if ( ! file_exists( $cache_path ) || filemtime( $local_path ) > filemtime( $cache_path ) ) {
					FileSystemUtil::createDirectory( $cache_dir );
					FileSystemUtil::writeFile( $cache_path, $optimized_content );
				}
				
				$optimized_url = content_url( 'cache/wppo/min/js/' . $filename );
				$tag = str_replace( $src, $optimized_url, $tag );
			}
		}

		return $tag;
	}

	/**
	 * Start HTML buffering.
	 */
	public function start_html_buffer(): void {
		if ( is_admin() || is_feed() || is_user_logged_in() ) {
			return;
		}
		ob_start( array( $this, 'minify_html_buffer' ) );
	}

	/**
	 * Minify HTML buffer.
	 *
	 * @param string $buffer HTML buffer.
	 * @return string Minified HTML.
	 */
	public function minify_html_buffer( string $buffer ): string {
		return $this->html_optimizer->optimize( $buffer );
	}
}
