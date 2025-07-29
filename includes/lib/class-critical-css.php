<?php
/**
 * Critical CSS generation for Performance Optimisation.
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
 * Class Critical_CSS
 *
 * @package PerformanceOptimise\Inc\Lib
 */
class Critical_CSS {

	/**
	 * Options for performance optimisation settings.
	 *
	 * @var array<string, mixed>
	 * @since 2.0.0
	 */
	private array $options;

	/**
	 * Critical_CSS constructor.
	 *
	 * @param array<string, mixed> $options The plugin options.
	 */
	public function __construct( array $options ) {
		$this->options = $options;
	}

	/**
	 * Register hooks for critical CSS generation.
	 */
	public function register_hooks(): void {
		if ( ! empty( $this->options['critical_css']['enabled'] ) ) {
			add_action( 'wp_head', array( $this, 'print_critical_css' ), 1 );
			add_filter( 'style_loader_tag', array( $this, 'defer_non_critical_css' ), 10, 2 );
		}
	}

	/**
	 * Print the critical CSS.
	 */
	public function print_critical_css(): void {
		$critical_css = get_option( 'wppo_critical_css' );
		if ( ! empty( $critical_css ) ) {
			echo '<style id="wppo-critical-css">' . $critical_css . '</style>';
		}
	}

	/**
	 * Defer non-critical CSS.
	 *
	 * @param string $tag    The style tag.
	 * @param string $handle The style handle.
	 * @return string The modified style tag.
	 */
	public function defer_non_critical_css( string $tag, string $handle ): string {
		if ( 'wppo-critical-css' === $handle ) {
			return $tag;
		}

		return str_replace( "rel='stylesheet'", "rel='preload' as='style' onload=\"this.rel='stylesheet'\"", $tag );
	}
}
