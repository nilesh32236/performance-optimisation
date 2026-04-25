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
		 * @return string 'apache', 'nginx', or 'other'.
		 */
		public static function get_server_type(): string {
			$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( $_SERVER['SERVER_SOFTWARE'] ) : '';

			if ( false !== strpos( $server_software, 'apache' ) ) {
				return 'apache';
			}

			if ( false !== strpos( $server_software, 'nginx' ) ) {
				return 'nginx';
			}

			// Fallback: check SAPI or common environment variables.
			if ( 'fpm-fcgi' === php_sapi_name() ) {
				// Nginx usually uses FPM.
				return 'nginx';
			}

			return 'other';
		}

		/**
		 * Get performance rules for Nginx configuration.
		 *
		 * @since  1.6.0
		 * @return string Nginx configuration snippet.
		 */
		public static function get_nginx_rules(): string {
			return implode( "\n", array(
				'# Gzip Compression',
				'gzip on;',
				'gzip_comp_level 5;',
				'gzip_min_length 256;',
				'gzip_proxied any;',
				'gzip_vary on;',
				'gzip_types',
				'    application/atom+xml',
				'    application/javascript',
				'    application/json',
				'    application/rss+xml',
				'    application/vnd.ms-fontobject',
				'    application/x-font-ttf',
				'    application/x-web-app-manifest+json',
				'    application/xhtml+xml',
				'    application/xml',
				'    font/opentype',
				'    image/svg+xml',
				'    image/x-icon',
				'    text/css',
				'    text/plain',
				'    text/x-component;',
				'',
				'# Browser Caching',
				'location ~* \.(jpg|jpeg|gif|png|webp|avif|svg|woff|woff2|ttf|otf|eot|ico|css|js)$ {',
				'    expires 365d;',
				'    add_header Cache-Control "public, no-transform";',
				'    access_log off;',
				'}',
			) );
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
			
			// Reflectively call private get_rules and join it.
			try {
				$reflection = new \ReflectionClass( 'PerformanceOptimise\Inc\Htaccess_Handler' );
				$method = $reflection->getMethod( 'get_rules' );
				$method->setAccessible( true );
				$rules = $method->invoke( null );
				return implode( "\n", $rules );
			} catch ( \Exception $e ) {
				return '';
			}
		}
	}
}
