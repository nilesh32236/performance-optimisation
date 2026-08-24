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

if ( ! class_exists( 'PerformanceOptimise\Inc\Core_Tweaks' ) ) {
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
				add_action( 'init', array( $this, 'disable_embeds' ), -1000 );
			}

			if ( ! empty( $this->settings['disableDashicons'] ) ) {
				add_action( 'wp_enqueue_scripts', array( $this, 'disable_dashicons' ) );
			}

			if ( ! empty( $this->settings['disableXMLRPC'] ) ) {
				// xmlrpc_methods is filtered to return empty array to block all methods.
				add_filter( 'xmlrpc_methods', array( $this, 'block_xmlrpc_methods' ) );
				add_filter( 'xmlrpc_enabled', '__return_false' );
				add_filter( 'wp_headers', array( $this, 'remove_x_pingback' ) );
			}

			$heartbeat_control = $this->settings['heartbeatControl'] ?? 'default';
			if ( 'default' !== $heartbeat_control ) {
				add_action( 'init', array( $this, 'control_heartbeat' ), 1 );
			}

			if ( ! empty( $this->settings['disableRestApiLinks'] ) ) {
				add_action( 'wp_head', array( $this, 'remove_rest_api_links' ), 100 );
				add_filter( 'rest_post_dispatch', array( $this, 'suppress_rest_header' ), 10, 3 );
			}

			if ( ! empty( $this->settings['disableRssFeeds'] ) ) {
				add_action( 'do_feed', array( $this, 'redirect_feed_to_home' ), 1 );
				add_action( 'do_feed_rdf', array( $this, 'redirect_feed_to_home' ), 1 );
				add_action( 'do_feed_rss', array( $this, 'redirect_feed_to_home' ), 1 );
				add_action( 'do_feed_rss2', array( $this, 'redirect_feed_to_home' ), 1 );
				add_action( 'do_feed_atom', array( $this, 'redirect_feed_to_home' ), 1 );
				add_action( 'do_feed_rss2_comments', array( $this, 'redirect_feed_to_home' ), 1 );
				add_action( 'do_feed_atom_comments', array( $this, 'redirect_feed_to_home' ), 1 );
				add_action( 'wp_head', array( $this, 'remove_feed_links' ), 100 );
			}

			if ( ! empty( $this->settings['disableShortlinks'] ) ) {
				remove_action( 'wp_head', 'wp_shortlink_wp_head' );
				add_filter( 'after_setup_theme', array( $this, 'remove_shortlink_tag' ) );
			}

			if ( ! empty( $this->settings['disableGeneratorTag'] ) ) {
				remove_action( 'wp_head', 'wp_generator' );
				add_filter( 'the_generator', '__return_empty_string' );
			}

			if ( ! empty( $this->settings['disableJQueryMigrate'] ) ) {
				add_action( 'wp_default_scripts', array( $this, 'remove_jquery_migrate' ) );
			}

			if ( ! empty( $this->settings['disablePasswordStrength'] ) ) {
				add_action( 'wp_print_scripts', array( $this, 'remove_password_strength_scripts' ), 100 );
			}

			if ( ! empty( $this->settings['disableSelfPingbacks'] ) ) {
				add_action( 'pre_ping', array( $this, 'disable_self_pingbacks' ) );
			}
		}

		/**
		 * Disable emojis.
		 *
		 * @return void
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
			return $plugins;
		}

		/**
		 * Remove emoji CDN hostname from DNS prefetching hints.
		 *
		 * @param array  $urls          URLs to print for resource hints.
		 * @param string $relation_type The relation type the URLs are printed for.
		 * @return array Difference betwen the two arrays.
		 */
		public function disable_emojis_remove_dns_prefetch( $urls, $relation_type ) {
			if ( 'dns-prefetch' === $relation_type && is_array( $urls ) ) {
				$emoji_svg_url = apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/15.0.3/svg/' );
				$urls          = array_diff( $urls, array( $emoji_svg_url ) );
			}
			return $urls;
		}

		/**
		 * Disable embeds.
		 *
		 * @return void
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
			return $plugins;
		}

		/**
		 * Remove embed rewrite rules.
		 *
		 * @param array $rules Rules.
		 * @return array
		 */
		public function disable_embeds_rewrites( $rules ) {
			if ( ! is_array( $rules ) ) {
				return $rules;
			}
			$new_rules = array();
			foreach ( $rules as $rule => $rewrite ) {
				if ( false === strpos( $rewrite, 'embed=true' ) ) {
					$new_rules[ $rule ] = $rewrite;
				}
			}
			return $new_rules;
		}

		/**
		 * Disable dashicons on frontend if not logged in.
		 *
		 * @return void
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
		 * Block all XML-RPC methods by returning an empty array.
		 *
		 * @param array $methods XML-RPC methods.
		 * @return array Empty array.
		 */
		public function block_xmlrpc_methods( $methods ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return array();
		}

		/**
		 * Control heartbeat depending on settings.
		 *
		 * @return void
		 */
		public function control_heartbeat() {
			$heartbeat_control = $this->settings['heartbeatControl'] ?? 'default';

			if ( 'disable_all' === $heartbeat_control ) {
				wp_deregister_script( 'heartbeat' );
			} elseif ( 'disable_ext' === $heartbeat_control && ! is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
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

		/**
		 * Remove REST API discovery links from the front end.
		 *
		 * @return void
		 */
		public function remove_rest_api_links() {
			remove_action( 'wp_head', 'rest_output_link_wp_head' );
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		}

		/**
		 * Suppress the Link REST response header.
		 *
		 * @param \WP_REST_Response $result  Result object.
		 * @param \WP_REST_Server   $server  Server instance.
		 * @param \WP_REST_Request  $request Request used to generate the response.
		 * @return \WP_REST_Response
		 */
		public function suppress_rest_header( $result, $server, $request = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Filter signature requires all 3 params; only $result is used.
			if ( $result instanceof \WP_REST_Response ) {
				// WP_REST_Response has no remove_header() (that lives on WP_REST_Server),
				// so rewrite the header list without Link (case-insensitive).
				$headers = $result->get_headers();
				$found   = false;
				foreach ( array_keys( $headers ) as $key ) {
					if ( 'link' === strtolower( $key ) ) {
						unset( $headers[ $key ] );
						$found = true;
					}
				}
				if ( $found ) {
					$result->set_headers( $headers );
				}
			}
			return $result;
		}

		/**
		 * Redirect feed requests to the site home page.
		 *
		 * @return void
		 */
		public function redirect_feed_to_home() {
			wp_safe_redirect( Util::cached_home_url( '/' ), 301 );
			exit;
		}

		/**
		 * Remove feed discovery links from the front end.
		 *
		 * @return void
		 */
		public function remove_feed_links() {
			remove_action( 'wp_head', 'feed_links', 2 );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}

		/**
		 * Remove the rel=shortlink tag output.
		 *
		 * @return void
		 */
		public function remove_shortlink_tag() {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		}

		/**
		 * Drop the jquery-migrate dependency from the jQuery handles.
		 *
		 * @param \WP_Scripts $scripts Scripts registry.
		 * @return void
		 */
		public function remove_jquery_migrate( $scripts ) {
			if ( ! isset( $scripts->registered['jquery'] ) || empty( $scripts->registered['jquery']->deps ) ) {
				return;
			}
			$scripts->registered['jquery']->deps = array_values(
				array_diff( $scripts->registered['jquery']->deps, array( 'jquery-migrate' ) )
			);
		}

		/**
		 * Deregister the password strength meter script on the front end.
		 *
		 * @return void
		 */
		public function remove_password_strength_scripts() {
			if ( ! is_admin() ) {
				wp_deregister_script( 'password-strength-meter' );
			}
		}

		/**
		 * Prevent a post from pinging itself.
		 *
		 * Compares the parsed host + port (and home path boundary) instead of a
		 * raw string prefix, so `https://example.com/…` matches a home of
		 * `http://example.com` while `http://example.com.evil/` does not.
		 *
		 * @param array $pung Pung URLs.
		 * @return void
		 */
		public function disable_self_pingbacks( &$pung ) {
			$home_parsed = wp_parse_url( (string) get_option( 'home' ) );
			$home_host   = isset( $home_parsed['host'] ) ? strtolower( (string) $home_parsed['host'] ) : '';
			$home_port   = isset( $home_parsed['port'] ) ? (int) $home_parsed['port'] : null;
			$home_path   = isset( $home_parsed['path'] ) ? rtrim( (string) $home_parsed['path'], '/' ) : '';

			foreach ( $pung as $key => $url ) {
				$parsed = wp_parse_url( (string) $url );
				$host   = isset( $parsed['host'] ) ? strtolower( (string) $parsed['host'] ) : '';
				if ( '' === $home_host || '' === $host || $host !== $home_host ) {
					continue;
				}
				$port = isset( $parsed['port'] ) ? (int) $parsed['port'] : null;
				if ( $port !== $home_port ) {
					continue;
				}
				$path = isset( $parsed['path'] ) ? (string) $parsed['path'] : '/';
				if ( '' !== $home_path && '/' !== $home_path && 0 !== strpos( $path, $home_path ) ) {
					continue;
				}
				unset( $pung[ $key ] );
			}
		}
	}
}
