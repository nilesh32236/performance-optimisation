<?php
/**
 * Resource Hints Service
 *
 * @package PerformanceOptimisation\Services
 * @since 2.1.0
 */

namespace PerformanceOptimisation\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ResourceHintsService
 */
class ResourceHintsService {

	private SettingsService $settings;

	public function __construct( SettingsService $settings ) {
		$this->settings = $settings;
	}

	public function init(): void {
		add_action( 'wp_head', array( $this, 'add_resource_hints' ), 2 );
	}

	public function add_resource_hints(): void {
		$this->add_dns_prefetch();
		$this->add_preconnect();
		$this->add_preload_images();
	}

	private function add_dns_prefetch(): void {
		$domains = $this->settings->get_setting( 'preloading', 'dns_prefetch', array() );

		foreach ( $domains as $domain ) {
			$domain = trim( $domain );
			if ( empty( $domain ) ) {
				continue;
			}
			printf( '<link rel="dns-prefetch" href="//%s">' . "\n", esc_attr( $domain ) );
		}
	}

	private function add_preconnect(): void {
		$domains = $this->settings->get_setting( 'preloading', 'preconnect', array() );

		foreach ( $domains as $domain ) {
			$domain = trim( $domain );
			if ( empty( $domain ) ) {
				continue;
			}
			// Add https:// if not present
			if ( strpos( $domain, 'http' ) !== 0 ) {
				$domain = 'https://' . $domain;
			}
			printf( '<link rel="preconnect" href="%s" crossorigin>' . "\n", esc_url( $domain ) );
		}
	}

	private function add_preload_images(): void {
		$images = $this->settings->get_setting( 'preloading', 'preload_images', array() );

		foreach ( $images as $image_url ) {
			$image_url = trim( $image_url );
			if ( empty( $image_url ) ) {
				continue;
			}

			printf( '<link rel="preload" href="%s" as="image">' . "\n", esc_url( $image_url ) );
		}
	}
}
