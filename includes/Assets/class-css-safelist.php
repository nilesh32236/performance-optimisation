<?php
/**
 * Shared CSS safelist guard for the Used_CSS and Critical_CSS pipelines.
 *
 * Single source for the Elementor/popup smoke check plus the shared
 * Elementor/popup preset list so a safelist fix applied to one pipeline
 * can never leave the other pipeline stripping builder CSS.
 *
 * @package PerformanceOptimise\Inc
 * @since   2.2.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Css_Safelist' ) ) {
	/**
	 * Shared CSS safelist guard.
	 *
	 * @since 2.2.0
	 */
	class Css_Safelist {

		/**
		 * Shared Elementor/popup preset selectors preserved by both pipelines.
		 *
		 * @since 2.2.0
		 * @return string[]
		 */
		public static function get_elementor_presets(): array {
			return array(
				'.elementor-',
				'.elementor-popup-',
				'.e-con*',
				'.e-popup-',
				'.dialog-',
				'.popup-',
				'.modal-',
				'.mfp-',
				'.swal2-',
				'[data-elementor-type]',
				'[data-elementor-type="popup"]',
			);
		}

		/**
		 * Whether purged CSS keeps required Elementor tokens.
		 *
		 * Byte-identical logic previously duplicated in Used_CSS and
		 * Critical_CSS. Fail-open: any uncertainty returns true (keep CSS).
		 *
		 * @since 2.2.0
		 * @param string $html       Source HTML.
		 * @param string $purged_css Purged CSS under test.
		 * @return bool True when the CSS passes the smoke check.
		 */
		public static function passes_elementor_smoke( string $html, string $purged_css ): bool {
			try {
				if ( '' === $html ) {
					return true;
				}
				$has_builder = false !== strpos( $html, 'data-elementor-type' )
					|| false !== strpos( $html, 'elementor-widget' )
					|| false !== strpos( $html, 'elementor-popup' );
				if ( ! $has_builder ) {
					return true;
				}
				if ( '' === trim( $purged_css ) ) {
					return false;
				}
				return false !== strpos( $purged_css, '.elementor' )
					|| false !== strpos( $purged_css, 'elementor-' )
					|| false !== strpos( $purged_css, 'data-elementor-type' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}
	}
}
