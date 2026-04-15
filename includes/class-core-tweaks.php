<?php
/**
 * Core Tweaks functionality to disable bloat.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.3.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Core_Tweaks
 */
class Core_Tweaks {

	/**
	 * Settings array.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param array $settings File optimization settings.
	 */
	public function __construct( $settings = array() ) {
		$this->settings = $settings;

		if ( ! empty( $this->settings['disableEmojis'] ) ) {
			add_action( 'init', array( $this, 'disable_emojis' ) );
		}

		if ( ! empty( $this->settings['disableEmbeds'] ) ) {
			add_action( 'init', array( $this, 'disable_embeds' ), 9999 );
		}

		if ( ! empty( $this->settings['disableDashicons'] ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'disable_dashicons' ) );
		}

		if ( ! empty( $this->settings['disableXMLRPC'] ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'wp_headers', array( $this, 'remove_x_pingback' ) );
		}

		$heartbeat_control = $this->settings['heartbeatControl'] ?? 'default';
		if ( 'default' !== $heartbeat_control ) {
			add_action( 'init', array( $this, 'control_heartbeat' ), 1 );
		}
	}

	/**
	 * Disable emojis.
	 */
	public function disable_emojis() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'tiny_mce_plugins', array( $this, 'disable_emojis_tinymce' ) );
		add_filter( 'wp_resource_hints', array( $this, 'disable_emojis_remove_dns_prefetch' ), 10, 2 );
	}

	/**
	 * Remove TinyMCE emoji plugin.
	 *
	 * @param array $plugins Plugins.
	 * @return array
	 */
	public function disable_emojis_tinymce( $plugins ) {
		if ( is_array( $plugins ) ) {
			return array_diff( $plugins, array( 'wpemoji' ) );
		}
		return array();
	}

	/**
	 * Remove emoji CDN hostname from DNS prefetching hints.
	 *
	 * @param array  $urls          URLs to print for resource hints.
	 * @param string $relation_type The relation type the URLs are printed for.
	 * @return array Difference betwen the two arrays.
	 */
	public function disable_emojis_remove_dns_prefetch( $urls, $relation_type ) {
		if ( 'dns-prefetch' === $relation_type ) {
			$emoji_svg_url = apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/15.0.3/svg/' );
			$urls          = array_diff( $urls, array( $emoji_svg_url ) );
		}
		return $urls;
	}

	/**
	 * Disable embeds.
	 */
	public function disable_embeds() {
		remove_action( 'rest_api_init', 'wp_oembed_register_route' );
		add_filter( 'embed_oembed_discover', '__return_false' );
		remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		add_filter( 'tiny_mce_plugins', array( $this, 'disable_embeds_tinymce' ) );
		add_filter( 'rewrite_rules_array', array( $this, 'disable_embeds_rewrites' ) );
		remove_filter( 'pre_oembed_result', 'wp_filter_pre_oembed_result', 10 );
	}

	/**
	 * Remove TinyMCE embed plugin.
	 *
	 * @param array $plugins Plugins.
	 * @return array
	 */
	public function disable_embeds_tinymce( $plugins ) {
		if ( is_array( $plugins ) ) {
			return array_diff( $plugins, array( 'wpembed' ) );
		}
		return array();
	}

	/**
	 * Remove embed rewrite rules.
	 *
	 * @param array $rules Rules.
	 * @return array
	 */
	public function disable_embeds_rewrites( $rules ) {
		$new_rules = array();
		if ( is_array( $rules ) ) {
			foreach ( $rules as $rule => $rewrite ) {
				if ( false === strpos( $rewrite, 'embed=true' ) ) {
					$new_rules[ $rule ] = $rewrite;
				}
			}
		}
		return $new_rules;
	}

	/**
	 * Disable dashicons on frontend if not logged in.
	 */
	public function disable_dashicons() {
		if ( ! is_user_logged_in() ) {
			wp_deregister_style( 'dashicons' );
		}
	}

	/**
	 * Remove X-Pingback header.
	 *
	 * @param array $headers Headers.
	 * @return array
	 */
	public function remove_x_pingback( $headers ) {
		if ( isset( $headers['X-Pingback'] ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}

	/**
	 * Control heartbeat depending on settings.
	 */
	public function control_heartbeat() {
		$heartbeat_control = $this->settings['heartbeatControl'] ?? 'default';

		if ( 'disable_all' === $heartbeat_control ) {
			wp_deregister_script( 'heartbeat' );
		} elseif ( 'disable_ext' === $heartbeat_control && ! is_admin() ) {
			wp_deregister_script( 'heartbeat' );
		} elseif ( '60s' === $heartbeat_control ) {
			add_filter( 'heartbeat_settings', array( $this, 'heartbeat_60s' ) );
		}
	}

	/**
	 * Set heartbeat to 60 seconds.
	 *
	 * @param array $settings Heartbeat settings.
	 * @return array
	 */
	public function heartbeat_60s( $settings ) {
		$settings['interval'] = 60;
		return $settings;
	}
}
