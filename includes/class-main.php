<?php
namespace PerformanceOptimise\Inc;

use voku\helper\HtmlMin;
use MatthiasMullie\Minify;

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

	/**
	 * Constructor.
	 *
	 * Initializes the class by including necessary files and setting up hooks.
	 */
	public function __construct() {
		$this->includes();
		$this->setup_hooks();
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
		require_once QTPO_PLUGIN_PATH . 'includes/class-util.php';
		require_once QTPO_PLUGIN_PATH . 'includes/class-cron.php';
	}

	/**
	 * Setup WordPress hooks.
	 *
	 * Registers actions and filters used by the plugin.
	 *
	 * @return void
	 */
	private function setup_hooks(): void {
		add_action( 'template_redirect', array( $this, 'generate_dynamic_static_html' ) );
		add_action( 'save_post', array( $this, 'invalidate_dynamic_static_html' ) );
		add_action( 'admin_menu', array( $this, 'init_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_filter( 'script_loader_tag', array( $this, 'add_defer_attribute' ), 10, 3 );

		new Cron();
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
		require_once QTPO_PLUGIN_PATH . 'templates/app.php';
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
		if ( 'toplevel_page_performance-optimisation' !== $screen->base ) {
			return;
		}

		wp_enqueue_style( 'performance-optimisation-style', QTPO_PLUGIN_URL . 'build/index.css', array(), '1.0.0', 'all' );
		wp_enqueue_script( 'performance-optimisation-script', QTPO_PLUGIN_URL . 'build/index.js', array( 'wp-element' ), '1.0.0', true );
	}

	/**
	 * Generate dynamic static HTML.
	 *
	 * Creates a static HTML version of the page if not logged in and not a 404 page.
	 *
	 * @return void
	 */
	public function generate_dynamic_static_html(): void {
		if ( is_user_logged_in() || is_404() ) {
			return;
		}

		global $wp_filesystem;

		$domain         = sanitize_text_field( $_SERVER['HTTP_HOST'] );
		$url_path       = trim( wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
		$cache_root_dir = WP_CONTENT_DIR . '/cache/qtpo';
		$cache_dir      = "{$cache_root_dir}/{$domain}" . ( '' === $url_path ? '' : "/{$url_path}" );
		$file_path      = "{$cache_dir}/index.html";
		$gzip_file_path = "{$cache_dir}/index.html.gz";

		if ( ! Util::init_filesystem() || ! Util::prepare_cache_dir( $cache_dir ) ) {
			return;
		}

		if ( ! $wp_filesystem->exists( "{$cache_root_dir}/cache-handler.php" ) ) {
			require_once QTPO_PLUGIN_PATH . 'includes/class-static-file-handler.php';
			Static_File_Handler::create();
		}
		$cache_expiry = 5 * HOUR_IN_SECONDS;
		$current_time = time();

		if ( ! $this->is_cache_valid( $file_path, $cache_expiry, $current_time ) ) {
			try {
				ob_start(
					function ( $buffer ) use ( $file_path, $gzip_file_path ) {
						$last_error = error_get_last();

						if ( $last_error && in_array( $last_error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
							error_log( 'Skipping static file generation due to a critical error: ' . print_r( $last_error, true ) );
							return $buffer;
						}

						$buffer = $this->minify_html( $buffer );

						if ( $last_error && in_array( $last_error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
							error_log( 'Skipping static file saving due to a critical error after minification: ' . print_r( $last_error, true ) );
							return $buffer;
						}

						$this->save_cache_files( $buffer, $file_path, $gzip_file_path );

						return $buffer;
					}
				);
				add_action( 'shutdown', 'ob_end_flush', 0, 0 );
			} catch ( \Exception $e ) {
				error_log( 'Error generating static HTML: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Check if the cache is valid.
	 *
	 * Determines if the cached file exists and is within the cache expiry time.
	 *
	 * @param string $file_path    Path to the cached HTML file.
	 * @param int    $cache_expiry Cache expiry time in seconds.
	 * @param int    $current_time Current timestamp.
	 * @return bool True if cache is valid, false otherwise.
	 */
	private function is_cache_valid( $file_path, $cache_expiry, $current_time ): bool {
		global $wp_filesystem;

		return $wp_filesystem->exists( $file_path ) && $cache_expiry >= ( $current_time - $wp_filesystem->mtime( $file_path ) );
	}

	/**
	 * Save cache files.
	 *
	 * Writes the minified HTML content to both regular and gzip compressed files.
	 *
	 * @param string $buffer         The minified HTML content.
	 * @param string $file_path      Path to save the regular HTML file.
	 * @param string $gzip_file_path Path to save the gzip compressed HTML file.
	 * @return void
	 */
	private function save_cache_files( $buffer, $file_path, $gzip_file_path ): void {
		global $wp_filesystem;

		if ( ! $wp_filesystem->put_contents( $file_path, $buffer, FS_CHMOD_FILE ) ) {
			error_log( 'Error writing static HTML file.' );
		}

		$gzip_output = gzencode( $buffer, 9 );
		if ( ! $wp_filesystem->put_contents( $gzip_file_path, $gzip_output, FS_CHMOD_FILE ) ) {
			error_log( 'Error writing gzipped static HTML file.' );
		}
	}

	/**
	 * Invalidate dynamic static HTML.
	 *
	 * Deletes the cached HTML files when a post is saved and schedules regeneration.
	 *
	 * @param int $post_id The ID of the post being saved.
	 * @return void
	 */
	public function invalidate_dynamic_static_html( $post_id ): void {
		$domain         = sanitize_text_field( $_SERVER['HTTP_HOST'] );
		$url_path       = trim( wp_parse_url( get_permalink( $post_id ), PHP_URL_PATH ), '/' );
		$file_name      = ( '' === $url_path ) ? 'index.html' : "{$url_path}/index.html";
		$cache_dir      = WP_CONTENT_DIR . "/cache/qtpo/{$domain}";
		$file_path      = "{$cache_dir}/{$file_name}";
		$gzip_file_path = "{$file_path}.gz";

		if ( Util::init_filesystem() ) {
			$this->delete_cache_files( $file_path, $gzip_file_path );
		}

		if ( ! wp_next_scheduled( 'qtpo_generate_static_page', array( $post_id ) ) ) {
			wp_schedule_single_event( time() + rand( 0, 30 ), 'qtpo_generate_static_page', array( $post_id ) );
		}
	}

	/**
	 * Delete cache files.
	 *
	 * Removes the cached HTML and gzip files for a specific page.
	 *
	 * @param string $file_path      Path to the regular HTML file.
	 * @param string $gzip_file_path Path to the gzip compressed HTML file.
	 * @return void
	 */
	private function delete_cache_files( $file_path, $gzip_file_path ): void {
		global $wp_filesystem;

		if ( $wp_filesystem->exists( $file_path ) ) {
			$wp_filesystem->delete( $file_path );
		}

		if ( $wp_filesystem->exists( $gzip_file_path ) ) {
			$wp_filesystem->delete( $gzip_file_path );
		}
	}

	/**
	 * Minify HTML content.
	 *
	 * Uses HtmlMin and Minify libraries to minify HTML, inline CSS, and inline JavaScript.
	 *
	 * @param string $html The HTML content to minify.
	 * @return string The minified HTML content.
	 */
	public function minify_html( $html ): string {
		$html_min = new HtmlMin();
		$html_min->doOptimizeViaHtmlDomParser( true )
			->doOptimizeAttributes( true )
			->doRemoveWhitespaceAroundTags( true )
			->doRemoveComments( true )
			->doSumUpWhitespace( true )
			->doRemoveEmptyAttributes( true )
			->doRemoveValueFromEmptyInput( true )
			->doSortCssClassNames( true )
			->doSortHtmlAttributes( true )
			->doRemoveSpacesBetweenTags( true );

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
			'#<script\b([^>]*)>(.*?)</script>#is',
			function ( $matches ) {
				$content = trim( $matches[2] );

				// Check if the content is empty or not
				if ( empty( $content ) ) {
					return $matches[0];
				}

				// Detect JSON content
				$is_json = ( isset( $content[0] ) && ( '{' === $content[0] || '[' === $content[0] ) );

				if ( $is_json && strpos( $matches[1], 'application/ld+json' ) !== false ) {
					$minified_json = json_encode( json_decode( $content, true ) );
					return '<script' . $matches[1] . '>' . $minified_json . '</script>';
				}

				if ( ! $is_json ) {
					$js_minifier = new Minify\JS( $matches[2] );
					$minified_js = $js_minifier->minify();
					return '<script' . $matches[1] . ' defer="defer">' . $minified_js . '</script>';
				}

				return $matches[0];
			},
			$html
		);

		return $html_min->minify( $html );
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
		if ( is_admin() ) {
			return $tag;
		}
		return str_replace( ' src', ' defer="defer" src', $tag );
	}
}
