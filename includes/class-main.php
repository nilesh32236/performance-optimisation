<?php
namespace PerformanceOptimise\Inc;

use PerformanceOptimise\Inc\Minify;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Class for Performance Optimisation.
 *
 * Handles the inclusion of necessary files, setup of hooks, and core functionalities
 * such as generating and invalidating dynamic static HTML.
 */

class Main {

	private array $exclude_css = array();
	private array $exclude_js  = array(
		'jquery',
	);
	private $filesystem;

	private $options;
	/**
	 * Constructor.
	 *
	 * Initializes the class by including necessary files and setting up hooks.
	 */
	public function __construct() {
		$this->options = get_option( 'qtpo_settings', array() );

		$this->includes();
		$this->setup_hooks();
		$this->filesystem = Util::init_filesystem();
	}

	/**
	 * Include required files.
	 *
	 * Loads the autoloader and includes other class files needed for the plugin.
	 *
	 * @return void
	 */
	private function includes(): void {
		require_once QTPO_PLUGIN_PATH . 'vendor/autoload.php';
		require_once QTPO_PLUGIN_PATH . 'includes/class-log.php';
		require_once QTPO_PLUGIN_PATH . 'includes/class-util.php';
		require_once QTPO_PLUGIN_PATH . 'includes/class-webp-converter.php';
		require_once QTPO_PLUGIN_PATH . 'includes/minify/class-html.php';
		require_once QTPO_PLUGIN_PATH . 'includes/minify/class-css.php';
		require_once QTPO_PLUGIN_PATH . 'includes/minify/class-js.php';
		require_once QTPO_PLUGIN_PATH . 'includes/class-cache.php';
		require_once QTPO_PLUGIN_PATH . 'includes/class-cron.php';
		require_once QTPO_PLUGIN_PATH . 'includes/class-rest.php';
	}

	/**
	 * Setup WordPress hooks.
	 *
	 * Registers actions and filters used by the plugin.
	 *
	 * @return void
	 */
	private function setup_hooks(): void {
		add_action( 'admin_menu', array( $this, 'init_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'script_loader_tag', array( $this, 'add_defer_attribute' ), 10, 3 );
		add_action( 'admin_bar_menu', array( $this, 'add_setting_to_admin_bar' ), 100 );

		if ( isset( $this->options['file_optimisation']['removeWooCSSJS'] ) && (bool) $this->options['file_optimisation']['removeWooCSSJS'] ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'remove_woocommerce_scripts' ), 999 );
		}

		$cache = new Cache();
		add_action( 'template_redirect', array( $cache, 'generate_dynamic_static_html' ) );
		add_action( 'save_post', array( $cache, 'invalidate_dynamic_static_html' ) );
		if ( isset( $this->options['file_optimisation']['combineCSS'] ) && (bool) $this->options['file_optimisation']['combineCSS'] ) {
			add_action( 'wp_enqueue_scripts', array( $cache, 'combine_css' ), PHP_INT_MAX );
		}

		$rest = new Rest();
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );

		$webp_converter = new WebP_Converter( $this->options );

		if ( isset( $this->options['image_optimisation']['convertToWebP'] ) && (bool) $this->options['image_optimisation']['convertToWebP'] ) {
			add_filter( 'wp_generate_attachment_metadata', array( $webp_converter, 'convert_images_to_webp' ), 10, 2 );
			add_filter( 'wp_get_attachment_image_src', array( $webp_converter, 'maybe_serve_webp_image' ), 10, 4 );
		}

		if ( isset( $this->options['file_optimisation']['minifyJS'] ) && (bool) $this->options['file_optimisation']['minifyJS'] ) {
			if ( isset( $this->options['file_optimisation']['excludeJS'] ) && ! empty( $this->options['file_optimisation']['excludeJS'] ) ) {
				$exclude_js = explode( "\n", $this->options['file_optimisation']['excludeJS'] );
				$exclude_js = array_map( 'trim', $exclude_js );
				$exclude_js = array_filter( $exclude_js );

				$this->exclude_js = array_merge( $this->exclude_js, (array) $exclude_js );
			}

			add_filter( 'script_loader_tag', array( $this, 'minify_js' ), 10, 3 );
		}

		if ( isset( $this->options['file_optimisation']['minifyCSS'] ) && (bool) $this->options['file_optimisation']['minifyCSS'] ) {
			if ( isset( $this->options['file_optimisation']['excludeCSS'] ) && ! empty( $this->options['file_optimisation']['excludeCSS'] ) ) {
				$exclude_css = explode( "\n", $this->options['file_optimisation']['excludeCSS'] );
				$exclude_css = array_map( 'trim', $exclude_css );
				$exclude_css = array_filter( $exclude_css );

				$this->exclude_css = array_merge( $this->exclude_css, (array) $exclude_css );
			}

			add_filter( 'style_loader_tag', array( $this, 'minify_css' ), 10, 3 );
		}
		new Cron();

		add_action( 'wp_head', array( $this, 'add_preload_prefatch_preconnect' ), 1 );
	}

	/**
	 * Initialize the admin menu.
	 *
	 * Adds the Performance Optimisation menu to the WordPress admin dashboard.
	 *
	 * @return void
	 */
	public function init_menu(): void {
		add_menu_page(
			__( 'Performance Optimisation', 'performance-optimisation' ),
			__( 'Performance Optimisation', 'performance-optimisation' ),
			'manage_options',
			'performance-optimisation',
			array( $this, 'admin_page' ),
			'dashicons-admin-post',
			'2.1',
		);
	}

	/**
	 * Display the admin page.
	 *
	 * Includes the admin page template for rendering.
	 *
	 * @return void
	 */
	public function admin_page(): void {
		require_once QTPO_PLUGIN_PATH . 'templates/app.html';
	}

	private function add_available_post_types_to_options() {
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		$excluded            = array( 'attachment' );
		$filtered_post_types = array_keys( array_diff( $post_types, $excluded ) );

		$this->options['image_optimisation']['availablePostTypes'] = $filtered_post_types;
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * Loads CSS and JavaScript files for the admin dashboard page.
	 *
	 * @return void
	 */
	public function admin_enqueue_scripts(): void {
		$screen = get_current_screen();

		if ( is_admin_bar_showing() ) {
			wp_enqueue_script( 'qtpo-admin-bar-script', QTPO_PLUGIN_URL . 'src/main.js', array(), '1.0.0', true );
			wp_localize_script(
				'qtpo-admin-bar-script',
				'qtpoObject',
				array(
					'apiUrl' => get_rest_url( null, 'performance-optimisation/v1' ),
					'nonce'  => wp_create_nonce( 'wp_rest' ),
				)
			);
		}

		if ( 'toplevel_page_performance-optimisation' !== $screen->base ) {
			return;
		}

		wp_enqueue_style( 'performance-optimisation-style', QTPO_PLUGIN_URL . 'build/style-index.css', array(), '1.0.0', 'all' );
		wp_enqueue_script( 'performance-optimisation-script', QTPO_PLUGIN_URL . 'build/index.js', array( 'wp-element' ), '1.0.0', true );

		$this->add_available_post_types_to_options();
		wp_localize_script(
			'performance-optimisation-script',
			'qtpoSettings',
			array(
				'apiUrl'         => get_rest_url( null, 'performance-optimisation/v1/' ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'settings'       => $this->options,
				'webp_converted' => get_option( 'qtpo_webp_converted', 0 ),
				'cache_size'     => Cache::get_cache_size(),
				'total_js_css'   => Util::get_js_css_minified_file(),
			),
		);
	}

	public function enqueue_scripts() {
		if ( is_admin_bar_showing() ) {
			wp_enqueue_script( 'qtpo-admin-bar-script', QTPO_PLUGIN_URL . 'src/main.js', array(), '1.0.0', true );
			wp_localize_script(
				'qtpo-admin-bar-script',
				'qtpoObject',
				array(
					'apiUrl' => get_rest_url( null, 'performance-optimisation/v1' ),
					'nonce'  => wp_create_nonce( 'wp_rest' ),
				)
			);
		}

		if ( ! is_user_logged_in() ) {
			wp_enqueue_script( 'qtpo-lazyload', QTPO_PLUGIN_URL . 'src/lazyload.js', array(), '1.0.0', true );
		}
	}

	public function remove_woocommerce_scripts() {
		$exclude_url_to_keep_js_css = array();
		if ( isset( $this->options['file_optimisation']['excludeUrlToKeepJSCSS'] ) && ! empty( $this->options['file_optimisation']['excludeUrlToKeepJSCSS'] ) ) {
			$exclude_url_to_keep_js_css = explode( "\n", $this->options['file_optimisation']['excludeUrlToKeepJSCSS'] );
			$exclude_url_to_keep_js_css = array_map( 'trim', $exclude_url_to_keep_js_css );
			$exclude_url_to_keep_js_css = array_filter( array_unique( $exclude_url_to_keep_js_css ) );

			$current_url = home_url( $_SERVER['REQUEST_URI'] );
			$current_url = rtrim( $current_url, '/' );

			foreach ( $exclude_url_to_keep_js_css as $exclude_url ) {
				if ( 0 !== strpos( $exclude_url, 'http' ) ) {
					$exclude_url = home_url( $exclude_url );
					$exclude_url = rtrim( $exclude_url, '/' );
				}

				if ( false !== strpos( $exclude_url, '(.*)' ) ) {
					$exclude_prefix = str_replace( '(.*)', '', $exclude_url );
					$exclude_prefix = rtrim( $exclude_prefix, '/' );

					if ( 0 === strpos( $current_url, $exclude_prefix ) ) {
						return;
					}
				}

				if ( $current_url === $exclude_url ) {
					return;
				}
			}
		}

		$remove_css_js_handle = array();
		if ( isset( $this->options['file_optimisation']['removeCssJsHandle'] ) && ! empty( $this->options['file_optimisation']['removeCssJsHandle'] ) ) {
			$remove_css_js_handle = explode( "\n", $this->options['file_optimisation']['removeCssJsHandle'] );
			$remove_css_js_handle = array_map( 'trim', $remove_css_js_handle );
			$remove_css_js_handle = array_filter( array_unique( $remove_css_js_handle ) );
		}

		if ( ! empty( $remove_css_js_handle ) ) {
			foreach ( $remove_css_js_handle as $handle ) {
				if ( 0 === strpos( $handle, 'style:' ) ) {
					$handle = str_replace( 'style:', '', $handle );
					$handle = trim( $handle );

					wp_dequeue_style( $handle );
				} elseif ( 0 === strpos( $handle, 'script:' ) ) {
					$handle = str_replace( 'script:', '', $handle );
					$handle = trim( $handle );

					wp_dequeue_script( $handle );
				}
			}
		}
	}

	public function add_setting_to_admin_bar( $wp_admin_bar ) {
		$wp_admin_bar->add_node(
			array(
				'id'    => 'qtpo_setting',
				'title' => __( 'Performance Optimisation', 'performance-optimisation' ),
				'href'  => admin_url( 'admin.php?page=performance-optimisation' ),
				'meta'  => array(
					'class' => 'performance-optimisation-setting',
					'title' => __( 'Go to Performance Optimisation Setting', 'performance-optimisation' ),
				),
			),
		);

		// Add a submenu under the custom setting
		$wp_admin_bar->add_node(
			array(
				'id'     => 'qtpo_clear_all',
				'parent' => 'qtpo_setting',
				'title'  => __( 'Clear All Cache', 'performance-optimisation' ),
				'href'   => '#',
			)
		);

		if ( ! is_admin() ) {
			$current_id = get_the_ID();

			$wp_admin_bar->add_node(
				array(
					'id'     => 'qtpo_clear_this_page',
					'parent' => 'qtpo_setting',
					'title'  => __( 'Clear This Page Cache', 'performance-optimisation' ),
					'href'   => '#', // You can replace with actual URL or function if needed
					'meta'   => array(
						'title' => __( 'Clear cache for this specific page or post', 'performance-optimisation' ),
						'class' => 'page-' . $current_id,
					),
				)
			);
		}
	}

	/**
	 * Add defer attribute to scripts.
	 *
	 * Filters script tags to add the defer attribute for non-admin pages.
	 *
	 * @param string $tag    The script tag HTML.
	 * @param string $handle The script's registered handle.
	 * @param string $src    The script's source URL.
	 * @return string Modified script tag with defer attribute.
	 */
	public function add_defer_attribute( $tag, $handle, $src ): string {
		if ( is_user_logged_in() ) {
			return $tag;
		}

		$exclude_js = array( 'qtpo-lazyload' );

		if ( isset( $this->options['file_optimisation']['deferJS'] ) && (bool) $this->options['file_optimisation']['deferJS'] ) {

			if ( isset( $this->options['file_optimisation']['excludeDeferJS'] ) && ! empty( $this->options['file_optimisation']['excludeDeferJS'] ) ) {
				$exclude_defer = explode( "\n", $this->options['file_optimisation']['excludeDeferJS'] );
				$exclude_defer = array_map( 'trim', $exclude_defer );
				$exclude_defer = array_filter( $exclude_defer );

				$exclude_defer = array_merge( $exclude_js, (array) $exclude_defer );
			} else {
				$exclude_defer = $exclude_js;
			}

			if ( ! in_array( $handle, $exclude_defer, true ) ) {
				$tag = str_replace( ' src', ' defer="defer" src', $tag );
			}
		}

		if ( isset( $this->options['file_optimisation']['delayJS'] ) && (bool) $this->options['file_optimisation']['delayJS'] ) {

			if ( isset( $this->options['file_optimisation']['excludeDelayJS'] ) && ! empty( $this->options['file_optimisation']['excludeDelayJS'] ) ) {
				$exclude_delay = explode( "\n", $this->options['file_optimisation']['excludeDelayJS'] );
				$exclude_delay = array_map( 'trim', $exclude_delay );
				$exclude_delay = array_filter( $exclude_delay );

				$exclude_delay = array_merge( $exclude_js, (array) $exclude_delay );
			} else {
				$exclude_delay = $exclude_js;
			}

			if ( ! in_array( $handle, $exclude_delay, true ) ) {
				$tag = str_replace( ' src', ' qtpo-src', $tag );
				$tag = preg_replace(
					'/type=("|\')text\/javascript("|\')/',
					'type="qtpo/javascript" qtpo-type="text/javascript"',
					$tag
				);
			}
		}

		return $tag;
	}

	private function get_image_mime_type( $url ) {
		// Infer MIME type from URL extension.
		$extension = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

		switch ( $extension ) {
			case 'jpg':
			case 'jpeg':
				return 'image/jpeg';
			case 'png':
				return 'image/png';
			case 'webp':
				return 'image/webp';
			case 'gif':
				return 'image/gif';
			case 'svg':
				return 'image/svg+xml';
			case 'avif':
				return 'image/avif';
			default:
				return '';
		}
	}

	public function add_preload_prefatch_preconnect() {
		// Preconnect origins
		if ( isset( $this->options['preload_settings']['preconnect'] ) && (bool) $this->options['preload_settings']['preconnect'] ) {
			if ( isset( $this->options['preload_settings']['preconnectOrigins'] ) && ! empty( $this->options['preload_settings']['preconnectOrigins'] ) ) {
				$preconnect_origins = explode( "\n", $this->options['preload_settings']['preconnectOrigins'] );
				$preconnect_origins = array_map( 'trim', $preconnect_origins );
				$preconnect_origins = array_filter( $preconnect_origins );

				foreach ( $preconnect_origins as $origin ) {
					echo '<link rel="preconnect" href="' . esc_url( $origin ) . '" crossorigin="anonymous">';
				}
			}
		}

		// Prefetch DNS origins
		if ( isset( $this->options['preload_settings']['prefetchDNS'] ) && (bool) $this->options['preload_settings']['prefetchDNS'] ) {
			if ( isset( $this->options['preload_settings']['dnsPrefetchOrigins'] ) && ! empty( $this->options['preload_settings']['dnsPrefetchOrigins'] ) ) {
				$dns_prefetch_origins = explode( "\n", $this->options['preload_settings']['dnsPrefetchOrigins'] );
				$dns_prefetch_origins = array_map( 'trim', $dns_prefetch_origins );
				$dns_prefetch_origins = array_filter( $dns_prefetch_origins );

				foreach ( $dns_prefetch_origins as $origin ) {
					echo '<link rel="dns-prefetch" href="' . esc_url( $origin ) . '">';
				}
			}
		}

		// Preload fonts
		if ( isset( $this->options['preload_settings']['preloadFonts'] ) && (bool) $this->options['preload_settings']['preloadFonts'] ) {
			if ( isset( $this->options['preload_settings']['preloadFontsUrls'] ) && ! empty( $this->options['preload_settings']['preloadFontsUrls'] ) ) {
				$preload_fonts_urls = explode( "\n", $this->options['preload_settings']['preloadFontsUrls'] );
				$preload_fonts_urls = array_map( 'trim', $preload_fonts_urls );
				$preload_fonts_urls = array_filter( $preload_fonts_urls );

				foreach ( $preload_fonts_urls as $font_url ) {

					if ( ! preg_match( '/^https?:\/\//i', $font_url ) ) {
						$font_url = content_url( $font_url );
					}

					$font_extension = pathinfo( wp_parse_url( $font_url, PHP_URL_PATH ), PATHINFO_EXTENSION );
					$font_type      = '';

					switch ( strtolower( $font_extension ) ) {
						case 'woff2':
							$font_type = 'font/woff2';
							break;
						case 'woff':
							$font_type = 'font/woff';
							break;
						case 'ttf':
							$font_type = 'font/ttf';
							break;
						default:
							$font_type = ''; // Fallback if unknown extension
					}

					echo '<link rel="preload" href="' . esc_url( $font_url ) . '" as="font" crossorigin="anonymous"' . ( ! empty( $font_type ) ? ' type="' . esc_attr( $font_type ) . '"' : '' ) . '>';
				}
			}
		}

		if ( isset( $this->options['preload_settings']['preloadCSS'] ) && (bool) $this->options['preload_settings']['preloadCSS'] ) {
			if ( isset( $this->options['preload_settings']['preloadCSSUrls'] ) && ! empty( $this->options['preload_settings']['preloadCSSUrls'] ) ) {
				$preload_css_urls = explode( "\n", $this->options['preload_settings']['preloadCSSUrls'] );
				$preload_css_urls = array_map( 'trim', $preload_css_urls );
				$preload_css_urls = array_filter( $preload_css_urls );

				foreach ( $preload_css_urls as $css_url ) {

					if ( ! preg_match( '/^https?:\/\//i', $css_url ) ) {
						$css_url = content_url( $css_url );
					}

					echo '<link rel="preload" href="' . esc_url( $css_url ) . '" as="style">';
				}
			}
		}

		if ( isset( $this->options['image_optimisation']['preloadFrontPageImages'] ) && (bool) $this->options['image_optimisation']['preloadFrontPageImages'] && is_front_page() ) {
			if ( isset( $this->options['image_optimisation']['preloadFrontPageImagesUrls'] ) && ! empty( $this->options['image_optimisation']['preloadFrontPageImagesUrls'] ) ) {
				$preload_img_urls = explode( "\n", $this->options['image_optimisation']['preloadFrontPageImagesUrls'] );
				$preload_img_urls = array_map( 'trim', $preload_img_urls );
				$preload_img_urls = array_filter( array_unique( $preload_img_urls ) );

				foreach ( $preload_img_urls as $img_url ) {
					if ( 0 === strpos( $img_url, 'mobile:' ) ) {
						$mobile_url = trim( str_replace( 'mobile:', '', $img_url ) );

						if ( 0 !== strpos( $img_url, 'http' ) ) {
							$mobile_url = content_url( $mobile_url );
						}

						$mime_type = $this->get_image_mime_type( $mobile_url );
						echo '<link rel="preload" href="' . esc_url( $mobile_url ) . '" as="image" media="(max-width: 768px)"' . ( ! empty( $mime_type ) ? ' type="' . esc_attr( $mime_type ) . '"' : '' ) . '>';
					} elseif ( 0 === strpos( $img_url, 'desktop:' ) ) {
						$desktop_url = trim( str_replace( 'desktop:', '', $img_url ) );

						if ( 0 !== strpos( $img_url, 'http' ) ) {
							$desktop_url = content_url( $desktop_url );
						}

						$mime_type = $this->get_image_mime_type( $desktop_url );
						echo '<link rel="preload" href="' . esc_url( $desktop_url ) . '" as="image" media="(min-width: 769px)"' . ( ! empty( $mime_type ) ? ' type="' . esc_attr( $mime_type ) . '"' : '' ) . '>';
					} else {
						$img_url = trim( $img_url );

						if ( 0 !== strpos( $img_url, 'http' ) ) {
							$img_url = content_url( $img_url );
						}

						$mime_type = $this->get_image_mime_type( $img_url );
						echo '<link rel="preload" href="' . esc_url( $img_url ) . '" as="image"' . ( ! empty( $mime_type ) ? ' type="' . esc_attr( $mime_type ) . '"' : '' ) . '>';
					}
				}
			}
		}

		if ( isset( $this->options['image_optimisation']['preloadPostTypeImage'] ) && (bool) $this->options['image_optimisation']['preloadPostTypeImage'] ) {
			if ( isset( $this->options['image_optimisation']['selectedPostType'] ) && ! empty( $this->options['image_optimisation']['selectedPostType'] ) ) {
				$selected_post_types = (array) $this->options['image_optimisation']['selectedPostType'];

				if ( is_singular( $selected_post_types ) && has_post_thumbnail() ) {
					global $post;
					$thumbnail_id = get_post_thumbnail_id( $post );

					if ( $thumbnail_id ) {
						$exclude_img_urls = array();
						if ( isset( $this->options['image_optimisation']['excludePostTypeImgUrl'] ) && ! empty( $this->options['image_optimisation']['excludePostTypeImgUrl'] ) ) {
							$exclude_img_urls = explode( "\n", $this->options['image_optimisation']['excludePostTypeImgUrl'] );
							$exclude_img_urls = array_map( 'trim', $exclude_img_urls );
							$exclude_img_urls = array_filter( array_unique( $exclude_img_urls ) );
						}

						if ( 'product' === $post->post_type && class_exists( 'WooCommerce' ) ) {
							$image_size = apply_filters( 'woocommerce_gallery_image_size', 'woocommerce_single' );
							$img_url    = wp_get_attachment_image_url( $thumbnail_id, $image_size );

							if ( is_array( $img_url ) ) {
								$img_url = $img_url[0];
							}
						} else {
							$img_url = wp_get_attachment_image_url( $thumbnail_id, 'medium' );
						}

						$should_exclude = false;
						foreach ( $exclude_img_urls as $url ) {
							if ( false !== strpos( $img_url, $url ) ) {
								$should_exclude = true;
								break;
							}
						}

						$max_width    = $this->options['image_optimisation']['maxWidthImgSize'] ? $this->options['image_optimisation']['maxWidthImgSize'] : 1480;
						$exclude_size = array();

						if ( isset( $this->options['image_optimisation']['excludeSize'] ) && ! empty( $this->options['image_optimisation']['excludeSize'] ) ) {
							$exclude_size = explode( "\n", $this->options['image_optimisation']['excludeSize'] );
							$exclude_size = array_map( 'trim', $exclude_size );
							$exclude_size = array_map( 'absint', $exclude_size );
							$exclude_size = array_filter( array_unique( $exclude_size ) );
						}

						if ( ! $should_exclude ) {
							$srcset = wp_get_attachment_image_srcset( $thumbnail_id );

							if ( $srcset ) {
								$sources = array_map( 'trim', explode( ',', $srcset ) );

								$parsed_sources = array();
								foreach ( $sources as $source ) {
									list( $url, $descriptor ) = array_map( 'trim', explode( ' ', $source ) );
									$width                    = (int) rtrim( $descriptor, 'w' ); // Remove 'w' to get the number.

									if ( in_array( (int) $width, $exclude_size, true ) ) {
										continue;
									}

									$parsed_sources[] = array(
										'url'   => $url,
										'width' => $width,
									);
								}

								usort(
									$parsed_sources,
									function ( $a, $b ) {
										return $a['width'] - $b['width'];
									}
								);

								$previous_width = 0;
								foreach ( $parsed_sources as $index => $source ) {
									$current_width = $source['width'];
									$next_width    = isset( $parsed_sources[ $index + 1 ] ) ? $parsed_sources[ $index + 1 ]['width'] : null;

									if ( $current_width > $max_width ) {
										continue;
									}

									$media = '(min-width: ' . $previous_width . 'px)';
									if ( $next_width && $next_width <= $max_width ) {
										$media .= ' and (max-width: ' . $current_width . 'px)';
									}

									$mime_type = $this->get_image_mime_type( $source['url'] );
									echo '<link rel="preload" href="' . esc_url( $source['url'] ) . '" as="image" media="' . esc_attr( $media ) . '"' . ( ! empty( $mime_type ) ? ' type="' . esc_attr( $mime_type ) . '"' : '' ) . '>';

									$previous_width = $current_width;
								}
							} else {
								$mime_type = $this->get_image_mime_type( $img_url );
								echo '<link rel="preload" href="' . esc_url( $img_url ) . '" as="image" media="(min-width: 0px)"' . ( ! empty( $mime_type ) ? ' type="' . esc_attr( $mime_type ) . '"' : '' ) . '>';
							}
						} else {
							error_log( "Image excluded: $img_url" );
						}
					}
				}
			}
		}
	}

	public function minify_css( $tag, $handle, $href ) {
		$local_path = Util::get_local_path( $href );

		if ( in_array( $handle, $this->exclude_css, true ) || empty( $href ) || $this->is_css_minified( $local_path ) || is_user_logged_in() ) {
			return $tag;
		}

		$css_minifier = new Minify\CSS( $local_path, WP_CONTENT_DIR . '/cache/qtpo/min/css' );
		$cached_file  = $css_minifier->minify();

		if ( $cached_file ) {
			$file_version = fileatime( Util::get_local_path( $cached_file ) );
			$new_href     = content_url( 'cache/qtpo/min/css/' . basename( $cached_file ) ) . '?ver=' . $file_version;
			$new_tag      = str_replace( $href, $new_href, $tag );
			return $new_tag;
		}

		return $tag;
	}

	public function minify_js( $tag, $handle, $src ) {
		global $wp_scripts;

		$local_path = Util::get_local_path( $src );

		if ( in_array( $handle, $this->exclude_js, true ) || empty( $src ) || $this->is_js_minified( $local_path ) || is_user_logged_in() ) {
			return $tag;
		}

		$js_minifier = new Minify\JS( $local_path, WP_CONTENT_DIR . '/cache/qtpo/min/js' );
		$cached_file = $js_minifier->minify();

		if ( $cached_file ) {
			$file_version = fileatime( Util::get_local_path( $cached_file ) );

			$new_src = content_url( 'cache/qtpo/min/js/' . basename( $cached_file ) ) . '?ver=' . $file_version;
			$new_tag = str_replace( $src, $new_src, $tag );
			return $new_tag;
		}

		return $tag;
	}

	private function is_css_minified( $file_path ) {
		$file_name = basename( $file_path );

		if ( preg_match( '/(\.min\.css|\.bundle\.css|\.bundle\.min\.css)$/i', $file_name ) ) {
			return true;
		}

		$css_content = $this->filesystem->get_contents( $file_path );
		$line        = preg_split( '/\r\n|\r|\n/', $css_content );

		if ( 10 >= count( $line ) ) {
			return true;
		}

		return false;
	}
	private function is_js_minified( $file_path ) {
		$file_name = basename( $file_path );

		if ( preg_match( '/(\.min\.js|\.bundle\.js|\.bundle\.min\.js)$/i', $file_name ) ) {
			return true;
		}

		$js_content = $this->filesystem->get_contents( $file_path );
		$line       = preg_split( '/\r\n|\r|\n/', $js_content );

		if ( 10 >= count( $line ) ) {
			return true;
		}

		return false;
	}
}
