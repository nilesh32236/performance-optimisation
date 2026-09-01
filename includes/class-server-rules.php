<?php
/**
 * Server_Rules class for the PerformanceOptimise plugin.
 *
 * Handles detection of server type and provides rules for Apache and Nginx.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.6.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Server_Rules' ) ) {

	/**
	 * Class Server_Rules
	 *
	 * Manages server-level performance rules.
	 *
	 * @since 1.6.0
	 */
	class Server_Rules {

		/**
		 * Detect the current server software.
		 *
		 * @since  1.6.0
		 * @return string 'apache', 'nginx', 'litespeed', or 'other'.
		 */
		public static function get_server_type(): string {
			$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
			$server_software = strtolower( $server_software );

			if ( false !== strpos( $server_software, 'litespeed' ) || false !== strpos( $server_software, 'openlitespeed' ) ) {
				return 'litespeed';
			}

			if ( false !== strpos( $server_software, 'apache' ) ) {
				return 'apache';
			}

			if ( false !== strpos( $server_software, 'nginx' ) ) {
				return 'nginx';
			}

			return 'other';
		}

		/**
		 * Check if the current server is LiteSpeed / OpenLiteSpeed.
		 *
		 * @since  NEXT
		 * @return bool True if LiteSpeed or OpenLiteSpeed is detected.
		 */
		public static function is_litespeed(): bool {
			return 'litespeed' === self::get_server_type();
		}

		/**
		 * Get performance rules for Nginx configuration.
		 *
		 * Includes LiteSpeed-style next-gen map (LS-402) when
		 * enableNextGenRewrite is true and convertImg is enabled.
		 * Opt-in default false. Filterable via wppo_litespeed_nextgen_rewrite.
		 *
		 * @since  1.6.0
		 * @return string Nginx configuration snippet.
		 */
		public static function get_nginx_rules(): string {
			$options = get_option( 'wppo_settings', array() );
			$rules   = array();

			// Gzip Compression.
			$minify_js  = isset( $options['file_optimisation']['minifyJS'] ) ? (bool) $options['file_optimisation']['minifyJS'] : false;
			$minify_css = isset( $options['file_optimisation']['minifyCSS'] ) ? (bool) $options['file_optimisation']['minifyCSS'] : false;

			if ( $minify_js || $minify_css ) {
				$rules[] = '# Gzip Compression';
				$rules[] = 'gzip on;';
				$rules[] = 'gzip_comp_level 5;';
				$rules[] = 'gzip_min_length 256;';
				$rules[] = 'gzip_proxied any;';
				$rules[] = 'gzip_vary on;';
				$rules[] = 'gzip_types';
				$rules[] = '    application/atom+xml';
				$rules[] = '    application/javascript';
				$rules[] = '    application/json';
				$rules[] = '    application/rss+xml';
				$rules[] = '    application/vnd.ms-fontobject';
				$rules[] = '    application/x-font-ttf';
				$rules[] = '    application/x-web-app-manifest+json';
				$rules[] = '    application/xhtml+xml';
				$rules[] = '    application/xml';
				$rules[] = '    font/opentype';
				$rules[] = '    image/svg+xml';
				$rules[] = '    image/x-icon';
				$rules[] = '    text/css';
				$rules[] = '    text/plain';
				$rules[] = '    text/x-component;';
				$rules[] = '';
			}

			// Browser Caching.
			$enable_rules = isset( $options['file_optimisation']['enableServerRules'] ) ? (bool) $options['file_optimisation']['enableServerRules'] : false;
			if ( $enable_rules ) {
				$rules[] = '# Browser Caching';
				$rules[] = 'location ~* \.(jpg|jpeg|gif|png|webp|avif|svg|woff|woff2|ttf|otf|eot|ico|css|js)$ {';
				$rules[] = '    expires 365d;';
				$rules[] = '    add_header Cache-Control "public, no-transform";';
				$rules[] = '    access_log off;';
				$rules[] = '}';
			}

			// LS-402: Nginx next-gen map — map $http_accept → try_files avif/webp.
			$use_nextgen = false;
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'is_nextgen_rewrite_enabled_for_nginx' ) ) {
				$use_nextgen = LiteSpeed_Integration::is_nextgen_rewrite_enabled_for_nginx();
			} else {
				$opts        = get_option( 'wppo_settings', array() );
				$enabled     = ! empty( $opts['litespeed_integration']['enableNextGenRewrite'] );
				$convert     = ! empty( $opts['image_optimisation']['convertImg'] );
				$use_nextgen = $enabled && $convert;
				/**
				 * Filter whether nginx next-gen map is enabled (fallback).
				 *
				 * @since NEXT
				 * @param bool $use_nextgen Whether next-gen map is enabled.
				 */
				$use_nextgen = (bool) apply_filters( 'wppo_litespeed_nextgen_rewrite', $use_nextgen );
			}

			if ( $use_nextgen ) {
				if ( ! empty( $rules ) ) {
					$rules[] = '';
				}
				$rules[] = '# WPPO Next-gen delivery (Nginx) — sibling .webp/.avif via Accept header';
				$rules[] = 'map $http_accept $wppo_avif_suffix {';
				$rules[] = '    default "";';
				$rules[] = '    "~*image/avif" ".avif";';
				$rules[] = '}';
				$rules[] = 'map $http_accept $wppo_webp_suffix {';
				$rules[] = '    default "";';
				$rules[] = '    "~*image/webp" ".webp";';
				$rules[] = '}';
				$rules[] = 'server {';
				$rules[] = '    location ~* \.(jpe?g|png)$ {';
				$rules[] = '        # Try avif first, then webp, then original — requires sibling .avif/.webp files';
				$rules[] = '        try_files $uri$wppo_avif_suffix $uri$wppo_webp_suffix $uri =404;';
				$rules[] = '        add_header Vary Accept;';
				$rules[] = '    }';
				$rules[] = '}';
				/**
				 * Filter nginx next-gen rules.
				 *
				 * @since NEXT
				 * @param bool $use_nextgen Whether next-gen map was added.
				 */
				$rules = (array) apply_filters( 'wppo_nginx_nextgen_rules', $rules );
			}

			// LS-320: Nginx Cache-Vary for ismobile/webp via map.
			$vary_groups = array();
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'get_vary_groups' ) ) {
				$vary_groups = LiteSpeed_Integration::get_vary_groups();
			} else {
				$vg_opts     = get_option( 'wppo_settings', array() );
				$vg          = $vg_opts['litespeed_integration']['varyGroups'] ?? array();
				$vary_groups = array(
					'mobile' => ! empty( $vg['mobile'] ),
					'webp'   => ! empty( $vg['webp'] ),
				);
			}
			if ( ! empty( $vary_groups['mobile'] ) || ! empty( $vary_groups['webp'] ) ) {
				if ( ! empty( $rules ) ) {
					$rules[] = '';
				}
				$rules[] = '# WPPO Cache-Vary (LS-320)';
				if ( ! empty( $vary_groups['mobile'] ) ) {
					$rules[] = 'map $http_user_agent $wppo_mobile_vary {';
					$rules[] = '    default "";';
					$rules[] = '    "~*Mobile|Android|Silk|Kindle|BlackBerry|Opera Mini|Opera Mobi" "ismobile";';
					$rules[] = '}';
				}
				if ( ! empty( $vary_groups['webp'] ) ) {
					$rules[] = 'map $http_accept $wppo_webp_vary {';
					$rules[] = '    default "";';
					$rules[] = '    "~*image/webp" "webp";';
					$rules[] = '}';
				}
				$cache_vary_parts = array();
				if ( ! empty( $vary_groups['mobile'] ) ) {
					$cache_vary_parts[] = '$wppo_mobile_vary';
				}
				if ( ! empty( $vary_groups['webp'] ) ) {
					$cache_vary_parts[] = '$wppo_webp_vary';
				}
				$rules[] = '# add_header Cache-Vary: ' . implode( ',', $cache_vary_parts ) . ' (when non-empty)';
				/**
				 * Filter nginx vary rules.
				 *
				 * @since NEXT
				 * @param array $rules Rules array.
				 * @param array $vary_groups Active vary groups.
				 */
				$rules = (array) apply_filters( 'wppo_nginx_vary_rules', $rules, $vary_groups );
			}

			$rules_str = implode( "\n", $rules );

			/**
			 * Filter nginx rules.
			 *
			 * @since NEXT
			 * @param string $rules_str Nginx rules.
			 */
			return apply_filters( 'wppo_nginx_rules', $rules_str );
		}

		/**
		 * Get Apache rules (proxied from Htaccess_Handler).
		 *
		 * @since  1.6.0
		 * @return string Apache rules snippet.
		 */
		public static function get_apache_rules(): string {
			if ( ! class_exists( 'PerformanceOptimise\Inc\Htaccess_Handler' ) ) {
				return '';
			}

			$rules = Htaccess_Handler::get_rules();
			return implode( "\n", $rules );
		}
	}
}
