<?php
/**
 * CDN integration for Performance Optimisation.
 *
 * @package PerformanceOptimise
 * @since 2.0.0
 */

namespace PerformanceOptimise\Inc\Lib;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CDN
 *
 * @package PerformanceOptimise\Inc\Lib
 */
class CDN {

	/**
	 * Options for performance optimisation settings.
	 *
	 * @var array<string, mixed>
	 * @since 2.0.0
	 */
	private array $options;

	/**
	 * CDN constructor.
	 *
	 * @param array<string, mixed> $options The plugin options.
	 */
	public function __construct( array $options ) {
		$this->options = $options;
	}

	/**
	 * Register hooks for CDN integration.
	 */
	public function register_hooks(): void {
		if ( ! empty( $this->options['cdn']['enabled'] ) ) {
			add_filter( 'wp_get_attachment_url', array( $this, 'rewrite_url' ) );
			add_filter( 'wp_get_attachment_image_src', array( $this, 'rewrite_url_in_array' ) );
			add_filter( 'wp_get_attachment_image_srcset', array( $this, 'rewrite_url_in_srcset' ) );
			add_filter( 'script_loader_src', array( $this, 'rewrite_url' ) );
			add_filter( 'style_loader_src', array( $this, 'rewrite_url' ) );
		}
	}

	/**
	 * Rewrite a URL to use the CDN.
	 *
	 * @param string $url The URL to rewrite.
	 * @return string The rewritten URL.
	 */
	public function rewrite_url( string $url ): string {
		if ( empty( $this->options['cdn']['url'] ) ) {
			return $url;
		}

		$cdn_url = $this->options['cdn']['url'];
		$site_url = get_site_url();

		return str_replace( $site_url, $cdn_url, $url );
	}

	/**
	 * Rewrite a URL in an array to use the CDN.
	 *
	 * @param array|false $src The array containing the URL to rewrite.
	 * @return array|false The array with the rewritten URL.
	 */
	public function rewrite_url_in_array( $src ) {
		if ( ! is_array( $src ) ) {
			return $src;
		}

		$src[0] = $this->rewrite_url( $src[0] );

		return $src;
	}

	/**
	 * Rewrite a URL in a srcset to use the CDN.
	 *
	 * @param string $srcset The srcset to rewrite.
	 * @return string The rewritten srcset.
	 */
	public function rewrite_url_in_srcset( string $srcset ): string {
		if ( empty( $this->options['cdn']['url'] ) ) {
			return $srcset;
		}

		$cdn_url = $this->options['cdn']['url'];
		$site_url = get_site_url();

		return str_replace( $site_url, $cdn_url, $srcset );
	}
}
