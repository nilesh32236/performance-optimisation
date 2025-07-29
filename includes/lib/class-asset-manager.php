<?php
/**
 * Asset Manager for Performance Optimisation.
 *
 * @package PerformanceOptimise
 * @since 2.0.0
 */

namespace PerformanceOptimise\Inc\Refactor;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Asset_Manager
 *
 * @package PerformanceOptimise\Inc\Refactor
 */
class Asset_Manager {

	/**
	 * Options for performance optimisation settings.
	 *
	 * @var array<string, mixed>
	 * @since 2.0.0
	 */
	private array $options;

	/**
	 * List of CSS handles to exclude from combining or minification.
	 * Updated by settings.
	 *
	 * @var array<string>
	 * @since 2.0.0
	 */
	private array $excluded_css_handles = array( 'wppo-combined-css' );

	/**
	 * List of JavaScript handles to exclude from minification.
	 * Updated by settings.
	 *
	 * @var array<string>
	 * @since 2.0.0
	 */
	private array $excluded_js_handles = array( 'jquery', 'jquery-core', 'jquery-migrate' );

	/**
	 * Filesystem instance for file operations.
	 *
	 * @var \WP_Filesystem_Base|null
	 * @since 2.0.0
	 */
	private ?\WP_Filesystem_Base $filesystem;

	/**
	 * Asset_Manager constructor.
	 *
	 * @param array<string, mixed> $options The plugin options.
	 */
	public function __construct( array $options ) {
		$this->options    = $options;
		$this->filesystem = Util::init_filesystem();
	}

	/**
	 * Register hooks for asset management.
	 */
	public function register_hooks(): void {
		add_filter( 'script_loader_tag', array( $this, 'modify_script_loader_tag' ), 10, 3 );
		add_filter( 'style_loader_tag', array( $this, 'modify_style_loader_tag' ), 10, 3 );
	}

	/**
	 * Modifies script loader tag for defer, delay, and minification.
	 *
	 * @since 2.0.0
	 * @param string $tag    The <script> tag for the enqueued script.
	 * @param string $handle The script's registered handle.
	 * @param string $src    The script's source URL.
	 * @return string Modified script tag.
	 */
	public function modify_script_loader_tag( string $tag, string $handle, string $src ): string {
		if ( is_user_logged_in() || is_admin() ) {
			return $tag;
		}

		$minify_js_enabled = ! empty( $this->options['file_optimisation']['minifyJS'] ) && (bool) $this->options['file_optimisation']['minifyJS'];
		if ( $minify_js_enabled && ! empty( $src ) && ! $this->is_handle_excluded( $handle, 'js' ) && ! $this->is_already_minified( $src, 'js' ) ) {
			try {
				$minifier     = new Minify\JS( Util::get_local_path( $src ), wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/min/js' ) );
				$minified_url = $minifier->minify();
				if ( $minified_url ) {
					$minified_local_path = Util::get_local_path( $minified_url );
					if ( $this->filesystem && $this->filesystem->exists( $minified_local_path ) ) {
					$version = md5_file( $minified_local_path );
						$new_src = esc_url( add_query_arg( 'ver', $version, $minified_url ) );
						$tag     = str_replace( esc_url( $src ), $new_src, $tag ); // Replace original src with minified.
						$src     = $new_src; // Update src for subsequent defer/delay logic.
					}
				}
			} catch ( \Exception $e ) {
				Log::log( 'Error minifying JS file: ' . $src . ' - ' . $e->getMessage() );
			}
		}

		$defer_js_enabled = ! empty( $this->options['file_optimisation']['deferJS'] ) && (bool) $this->options['file_optimisation']['deferJS'];
		if ( $defer_js_enabled && ! $this->is_handle_excluded( $handle, 'defer_js' ) ) {
			if ( strpos( $tag, 'type="module"' ) === false ) {
				$tag = str_replace( ' src=', ' defer src=', $tag );
			}
		}

		$delay_js_enabled = ! empty( $this->options['file_optimisation']['delayJS'] ) && (bool) $this->options['file_optimisation']['delayJS'];
		if ( $delay_js_enabled && ! $this->is_handle_excluded( $handle, 'delay_js' ) ) {
			if ( strpos( $tag, ' src=' ) !== false ) {
				$tag = str_replace( ' src=', ' data-wppo-src=', $tag );
			}
			$original_type = 'text/javascript';
			if ( preg_match( '/type=(["\'])(.*?)\1/', $tag, $type_match ) ) {
				$original_type = $type_match[2];
				$tag           = str_replace( $type_match[0], '', $tag ); // Remove original type attribute.
			}
			$tag = str_replace( '<script', '<script type="wppo/javascript" data-wppo-type="' . esc_attr( $original_type ) . '"', $tag );
		}

		return $tag;
	}

	/**
	 * Modifies style loader tag for minification.
	 *
	 * @since 2.0.0
	 * @param string $tag    The <link> tag for the enqueued style.
	 * @param string $handle The style's registered handle.
	 * @param string $href   The style's source URL.
	 * @return string Modified style tag.
	 */
	public function modify_style_loader_tag( string $tag, string $handle, string $href ): string {
		if ( is_user_logged_in() || is_admin() || empty( $href ) ) {
			return $tag;
		}

		$minify_css_enabled = ! empty( $this->options['file_optimisation']['minifyCSS'] ) && (bool) $this->options['file_optimisation']['minifyCSS'];

		if ( $minify_css_enabled && 'wppo-combined-css' !== $handle && ! $this->is_handle_excluded( $handle, 'css' ) && ! $this->is_already_minified( $href, 'css' ) ) {
			try {
				$minifier     = new Minify\CSS( Util::get_local_path( $href ), wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/min/css' ) );
				$minified_url = $minifier->minify();

				if ( $minified_url ) {
					$minified_local_path = Util::get_local_path( $minified_url );
					if ( $this->filesystem && $this->filesystem->exists( $minified_local_path ) ) {
					$version  = md5_file( $minified_local_path );
						$new_href = esc_url( add_query_arg( 'ver', $version, $minified_url ) );
						$tag      = str_replace( esc_url( $href ), $new_href, $tag );
					}
				}
			} catch ( \Exception $e ) {
				Log::log( 'Error minifying CSS file: ' . $href . ' - ' . $e->getMessage() );
			}
		}
		return $tag;
	}

	/**
	 * Checks if a handle is excluded based on settings.
	 *
	 * @param string $handle Handle of the script/style.
	 * @param string $type   Type of exclusion list ('js', 'css', 'combine_css', 'defer_js', 'delay_js').
	 * @return bool True if excluded, false otherwise.
	 */
	private function is_handle_excluded( string $handle, string $type ): bool {
		$setting_key_map = array(
			'js'          => 'excludeJS',
			'css'         => 'excludeCSS',
			'combine_css' => 'excludeCombineCSS',
			'defer_js'    => 'excludeDeferJS',
			'delay_js'    => 'excludeDelayJS',
		);

		if ( ! isset( $setting_key_map[ $type ] ) ) {
			return false;
		}
		$setting_key = $setting_key_map[ $type ];

		$default_exclusions = array();
		if ( 'js' === $type || 'defer_js' === $type || 'delay_js' === $type ) {
			$default_exclusions = $this->excluded_js_handles;
			if ( 'defer_js' === $type || 'delay_js' === $type ) {
				$default_exclusions[] = 'wppo-lazyload'; // Lazyload script should not be deferred/delayed.
			}
		} elseif ( 'css' === $type || 'combine_css' === $type ) {
			$default_exclusions = $this->excluded_css_handles;
		}

		$user_exclusions_string = $this->options['file_optimisation'][ $setting_key ] ?? '';
		$user_exclusions        = Util::process_urls( (string) $user_exclusions_string );

		$all_exclusions = array_unique( array_merge( $default_exclusions, $user_exclusions ) );

		if ( in_array( $handle, $all_exclusions, true ) ) {
			return true;
		}

		global $wp_scripts, $wp_styles;
		$asset_src = '';
		if ( 'js' === $type || 'defer_js' === $type || 'delay_js' === $type ) {
			if ( isset( $wp_scripts->registered[ $handle ] ) ) {
				$asset_src = $wp_scripts->registered[ $handle ]->src;
			}
		} elseif ( 'css' === $type || 'combine_css' === $type ) {
			if ( isset( $wp_styles->registered[ $handle ] ) ) {
				$asset_src = $wp_styles->registered[ $handle ]->src;
			}
		}

		if ( $asset_src ) {
			foreach ( $all_exclusions as $exclusion_pattern ) {
				if ( str_contains( $asset_src, $exclusion_pattern ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Checks if a CSS/JS file URL suggests it's already minified.
	 *
	 * @since 2.0.0
	 * @param string $url  Path or URL to the asset file.
	 * @param string $type 'css' or 'js'.
	 * @return bool True if the file seems minified, false otherwise.
	 */
	private function is_already_minified( string $url, string $type ): bool {
		$file_name = basename( wp_parse_url( $url, PHP_URL_PATH ) );

		if ( preg_match( '/(\.min\.|\.bundle\.|\-min\.)' . $type . '$/i', $file_name ) ) {
			return true;
		}

		if ( str_contains( $url, '/cache/wppo/min/' ) ) {
			return true;
		}

		if ( $this->filesystem ) {
			$local_path = Util::get_local_path( $url );
			if ( $this->filesystem->exists( $local_path ) && $this->filesystem->is_readable( $local_path ) ) {
				$content = $this->filesystem->get_contents( $local_path );
				if ( $content ) {
					$lines = preg_split( '/\r\n|\r|\n/', $content );
					if ( count( $lines ) <= 10 ) {
						return true;
					}
				}
			}
		}

		return false;
	}
}
