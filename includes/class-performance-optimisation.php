<?php

use voku\helper\HtmlMin;
use MatthiasMullie\Minify;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Performance_Optimisation {
	public function __construct() {
		$this->includes();
		$this->setup_hooks();
	}

	private function includes() {
		require_once QTPM_PLUGIN_PATH . 'includes/class-cron.php';
	}

	private function setup_hooks() {
		add_action( 'template_redirect', array( $this, 'generate_dynamic_static_html' ) );
		add_action( 'save_post', array( $this, 'invalidate_dynamic_static_html' ) );
		add_action( 'admin_menu', array( $this, 'init_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		// add_filter( 'script_loader_tag', array( $this, 'add_defer_attribute' ), 10, 3 );
	}

	public function init_menu() {
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

	public function admin_page() {
		require_once QTPM_PLUGIN_PATH . 'templates/app.php';
	}

	public function admin_enqueue_scripts() {
		$screen = get_current_screen();
		if ( 'toplevel_page_performance-optimisation' !== $screen->base ) {
			return;
		}

		wp_enqueue_style( 'performance-optimisation-style', QTPM_PLUGIN_URL . 'build/index.css', array(), '1.0.0', 'all' );
		wp_enqueue_script( 'performance-optimisation-script', QTPM_PLUGIN_URL . 'build/index.js', array( 'wp-element' ), '1.0.0', true );
	}

	public function generate_dynamic_static_html() {
		if ( is_404() ) {
			return;
		}

		$domain   = sanitize_text_field( $_SERVER['HTTP_HOST'] );
		$url_path = trim( wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );

		error_log( $url_path );
		$cache_dir      = WP_CONTENT_DIR . "/cache/qtpm/{$domain}" . ( '' === $url_path ? '' : "/{$url_path}" );
		$file_path      = "{$cache_dir}/index.html";
		$gzip_file_path = "{$cache_dir}/index.html.gz";

		if ( ! $this->init_filesystem() || ! $this->prepare_cache_dir( $cache_dir ) ) {
			return;
		}

		$cache_expiry = 5 * HOUR_IN_SECONDS;
		$current_time = time();

		if ( ! $this->is_cache_valid( $file_path, $cache_expiry, $current_time ) ) {
			try {
				ob_start(
					function ( $buffer ) use ( $file_path, $gzip_file_path ) {
						$buffer = $this->minify_html( $buffer );

						$error = error_get_last();
						if ( 1 !== $error['type'] ) {
							$this->save_cache_files( $buffer, $file_path, $gzip_file_path );
						} else {
							error_log( 'Skipping static file generation due to an error: ' . print_r( $error, true ) );
						}
						return $buffer;
					}
				);
				add_action( 'shutdown', 'ob_end_flush', 0, 0 );
			} catch ( Exception $e ) {
				error_log( 'Error generating static HTML: ' . $e->getMessage() );
			}
		}
	}

	private function init_filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return WP_Filesystem();
	}

	private function prepare_cache_dir( $cache_dir ) {
		global $wp_filesystem;

		// Check if the directory already exists
		if ( ! $wp_filesystem->is_dir( $cache_dir ) ) {

			// Recursively create parent directories first
			$parent_dir = dirname( $cache_dir );
			error_log( '$parent_dir: ' . $parent_dir );
			if ( ! $wp_filesystem->is_dir( $parent_dir ) ) {
				$this->prepare_cache_dir( $parent_dir );
			}

			// Create the final directory
			if ( ! $wp_filesystem->mkdir( $cache_dir, FS_CHMOD_DIR ) ) {
				error_log( "Failed to create directory using WP_Filesystem: $cache_dir" );
				return false;
			}
		}

		return true;
	}

	private function is_cache_valid( $file_path, $cache_expiry, $current_time ) {
		global $wp_filesystem;

		return $wp_filesystem->exists( $file_path ) && $cache_expiry >= ( $current_time - $wp_filesystem->mtime( $file_path ) );
	}

	private function save_cache_files( $buffer, $file_path, $gzip_file_path ) {
		global $wp_filesystem;

		error_log( $file_path );
		if ( ! $wp_filesystem->put_contents( $file_path, $buffer, FS_CHMOD_FILE ) ) {
			error_log( 'Error writing static HTML file.' );
		}

		$gzip_output = gzencode( $buffer, 9 );
		if ( ! $wp_filesystem->put_contents( $gzip_file_path, $gzip_output, FS_CHMOD_FILE ) ) {
			error_log( 'Error writing gzipped static HTML file.' );
		}
	}

	public function invalidate_dynamic_static_html( $post_id ) {
		$domain         = sanitize_text_field( $_SERVER['HTTP_HOST'] );
		$url_path       = trim( wp_parse_url( get_permalink( $post_id ), PHP_URL_PATH ), '/' );
		$file_name      = ( '' === $url_path ) ? 'index.html' : "{$url_path}/index.html";
		$cache_dir      = WP_CONTENT_DIR . "/cache/qtpm/{$domain}";
		$file_path      = "{$cache_dir}/{$file_name}";
		$gzip_file_path = "{$file_path}.gz";

		if ( $this->init_filesystem() ) {
			$this->delete_cache_files( $file_path, $gzip_file_path );
		}
	}

	private function delete_cache_files( $file_path, $gzip_file_path ) {
		global $wp_filesystem;

		if ( $wp_filesystem->exists( $file_path ) ) {
			$wp_filesystem->delete( $file_path );
		}

		if ( $wp_filesystem->exists( $gzip_file_path ) ) {
			$wp_filesystem->delete( $gzip_file_path );
		}
	}

	public function minify_html( $html ) {
		$html_min = new HtmlMin();
		$html_min->doOptimizeViaHtmlDomParser( true );
		$html_min->doOptimizeAttributes( true );
		$html_min->doRemoveWhitespaceAroundTags( true );
		$html_min->doRemoveComments( true );
		$html_min->doSumUpWhitespace( true );
		$html_min->doRemoveEmptyAttributes( true );
		$html_min->doRemoveValueFromEmptyInput( true );
		$html_min->doSortCssClassNames( true );
		$html_min->doSortHtmlAttributes( true );
		$html_min->doRemoveSpacesBetweenTags( true );

		// Minify inline CSS
		$html = preg_replace_callback(
			'#<style\b[^>]*>(.*?)</style>#is',
			function ( $matches ) {
				$css_minifier = new Minify\CSS( $matches[1] );
				$minified_css = $css_minifier->minify();
				return '<style>' . $minified_css . '</style>';
			},
			$html
		);

		// Minify inline JS
		$html = preg_replace_callback(
			'#<script\b[^>]*>(.*?)</script>#is',
			function ( $matches ) {
				// Skip JSON LD scripts or other script types
				if ( strpos( $matches[0], 'application/ld+json' ) !== false ) {
					return $matches[0];
				}
				$js_minifier = new Minify\JS( $matches[1] );
				$minified_js = $js_minifier->minify();
				return '<script>' . $minified_js . '</script>';
			},
			$html
		);

		// Minify the HTML
		$minifiedHtml = $html_min->minify( $html );

		return $minifiedHtml;
	}

	public function add_defer_attribute( $tag, $handle, $src ) {
		return str_replace( ' src', ' defer="defer" src', $tag );
	}
}
