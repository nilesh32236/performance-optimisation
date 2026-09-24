<?php
/**
 * Frontend preload, output-buffer, and cache-warm coordination.
 *
 * P3-015: owns the bounded cluster coordinated by Main for legacy used-CSS/LCP
 * buffers, the WP 6.9+ template-enhancement route, and cache-aware scheduling
 * seams. Public Main callbacks remain stable facades and Hook_Registry keeps the
 * same callback objects, priorities, and order.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Preload_Buffer_Coordinator' ) ) {
	/**
	 * Coordinates frontend buffering and cache-warm scheduling.
	 *
	 * Collaborators are injected through narrow callables so this owner does not
	 * depend on Main. The coordinator preserves the former one-shot used-CSS
	 * lifecycle, legacy fallback guards, fail-open output, and cache scheduling
	 * decisions without moving speculation, image serving, or LiteSpeed policy.
	 *
	 * @since NEXT
	 */
	final class Preload_Buffer_Coordinator {

		/**
		 * Resolved Main settings reader.
		 *
		 * @var callable(): array
		 * @since NEXT
		 */
		private $get_options;

		/**
		 * Image LCP output callback target.
		 *
		 * @var Image_Optimisation
		 * @since NEXT
		 */
		private Image_Optimisation $image_optimisation;

		/**
		 * Google Fonts output callback target.
		 *
		 * @var Google_Fonts
		 * @since NEXT
		 */
		private Google_Fonts $google_fonts;

		/**
		 * Safe-mode decision port.
		 *
		 * @var callable(array): bool
		 * @since NEXT
		 */
		private $safe_mode_active;

		/**
		 * Aggressive-bypass decision port.
		 *
		 * @var callable(): bool
		 * @since NEXT
		 */
		private $aggressive_bypass_active;

		/**
		 * Atomic Action Scheduler enqueue port.
		 *
		 * @var callable(string,array,string): void
		 * @since NEXT
		 */
		private $enqueue_unique_async_action;

		/**
		 * Whether the used-CSS pipeline already ran this request.
		 *
		 * @var bool
		 * @since NEXT
		 */
		private bool $used_css_buffer_enhanced = false;

		/**
		 * Constructor.
		 *
		 * @since NEXT
		 * @param callable           $get_options             Resolved options reader.
		 * @param Image_Optimisation $image_optimisation     LCP output callback target.
		 * @param Google_Fonts       $google_fonts           Google Fonts output callback target.
		 * @param callable           $safe_mode_active       Safe-mode decision port.
		 * @param callable           $aggressive_bypass_active Aggressive-bypass decision port.
		 * @param callable|null      $enqueue_unique_async_action Optional atomic scheduling port.
		 */
		public function __construct(
			callable $get_options,
			Image_Optimisation $image_optimisation,
			Google_Fonts $google_fonts,
			callable $safe_mode_active,
			callable $aggressive_bypass_active,
			?callable $enqueue_unique_async_action = null
		) {
			$this->get_options                 = $get_options;
			$this->image_optimisation          = $image_optimisation;
			$this->google_fonts                = $google_fonts;
			$this->safe_mode_active            = $safe_mode_active;
			$this->aggressive_bypass_active    = $aggressive_bypass_active;
			$this->enqueue_unique_async_action = $enqueue_unique_async_action ?? array( Util::class, 'enqueue_unique_async_action' );
		}

		/**
		 * Whether WP 6.9+ core owns the template-enhancement output buffer.
		 *
		 * Availability only: a runtime core opt-out never re-enables a private
		 * legacy buffer on a 6.9+ install.
		 *
		 * @since NEXT
		 * @return bool True when core's template-enhancement path is available.
		 */
		public static function should_use_core_template_buffer(): bool {
			try {
				if ( ! Wp_Version::is_at_least( '6.9-alpha' ) ) {
					return false;
				}
				return function_exists( 'wp_should_output_buffer_template_for_enhancement' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Process used-CSS on the WP 6.9+ enhancement filter path.
		 *
		 * @since NEXT
		 * @param mixed $filtered_output Output from earlier enhancement filters.
		 * @param mixed $output          Raw template output.
		 * @return string Fail-open HTML string.
		 */
		public function process_used_css_only( $filtered_output, $output ) {
			if ( ! is_string( $filtered_output ) ) {
				if ( is_string( $output ) && '' !== $output ) {
					return $output;
				}
				return '';
			}
			try {
				if ( ! $this->should_optimise_for_logged_in() || is_admin() ) {
					return $filtered_output;
				}
				$options = ( $this->get_options )();
				if ( ( $this->safe_mode_active )( $options['file_optimisation'] ?? array() ) || ( $this->aggressive_bypass_active )() ) {
					return $filtered_output;
				}
				if ( $this->used_css_buffer_enhanced ) {
					return $filtered_output;
				}
				$this->used_css_buffer_enhanced = true;

				if ( ! empty( $options['file_optimisation']['hostGoogleFontsLocally'] ?? false ) ) {
					$filtered_output = $this->google_fonts->process_buffer( $filtered_output );
				}

				$used_css = new Used_CSS( $options );
				$result   = $used_css->process_buffer( $filtered_output );
				return is_string( $result ) ? $result : $filtered_output;
			} catch ( \Throwable $e ) {
				do_action( 'wppo_debug_log', 'WPPO used-CSS buffer processing failed.', array( 'exception' => $e ) );
				if ( is_string( $filtered_output ) && '' !== $filtered_output ) {
					return $filtered_output;
				}
				if ( is_string( $output ) && '' !== $output ) {
					return $output;
				}
				return '';
			}
		}

		/**
		 * Start the legacy used-CSS buffer on supported pre-6.9 paths.
		 *
		 * @since NEXT
		 * @return void
		 */
		public function start_used_css_buffer(): void {
			if ( $this->legacy_buffer_owned_by_core() ) {
				return;
			}
			if ( ! $this->should_optimise_for_logged_in() || is_admin() ) {
				return;
			}
			$options = ( $this->get_options )();
			if ( ( $this->safe_mode_active )( $options['file_optimisation'] ?? array() ) || ( $this->aggressive_bypass_active )() ) {
				return;
			}
			ob_start( array( $this, 'process_used_css_capture' ) );
		}

		/**
		 * Start the legacy LCP buffer on supported pre-6.9 paths.
		 *
		 * @since NEXT
		 * @return void
		 */
		public function start_lcp_priority_buffer(): void {
			if ( $this->legacy_buffer_owned_by_core() ) {
				return;
			}
			if ( ! $this->should_optimise_for_logged_in() || is_admin() ) {
				return;
			}
			if ( is_feed() || is_robots() || is_trackback() || is_preview() || is_embed() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}
			ob_start( array( $this->image_optimisation, 'prioritize_lcp_in_buffer' ) );
		}

		/**
		 * Process the legacy used-CSS output buffer.
		 *
		 * @since NEXT
		 * @param mixed $buffer Output-buffer content.
		 * @return string Fail-open HTML string.
		 */
		public function process_used_css_capture( $buffer ) {
			if ( ! is_string( $buffer ) ) {
				return '';
			}
			if ( '' === $buffer ) {
				return $buffer;
			}
			$original = $buffer;
			try {
				if ( $this->used_css_buffer_enhanced ) {
					return $original;
				}
				$this->used_css_buffer_enhanced = true;
				$options                        = ( $this->get_options )();
				if ( ! empty( $options['file_optimisation']['hostGoogleFontsLocally'] ?? false ) ) {
					$buffer = $this->google_fonts->process_buffer( $buffer );
				}
				$used_css = new Used_CSS( $options );
				$result   = $used_css->process_buffer( $buffer );
				return is_string( $result ) ? $result : $original;
			} catch ( \Throwable $e ) {
				do_action( 'wppo_debug_log', 'WPPO used-CSS buffer processing failed.', array( 'exception' => $e ) );
				return $original;
			}
		}

		/**
		 * Queue a cache warm after smart purge when sitemap preload is enabled.
		 *
		 * @since NEXT
		 * @internal Optional settings override is a focused test seam.
		 * @param int        $post_id Saved post ID.
		 * @param array|null $settings Optional settings snapshot.
		 * @return void
		 */
		public function queue_crawler_warm_after_cache_invalidation( int $post_id, ?array $settings = null ): void {
			$options = $settings ?? Util::get_settings();
			if ( empty( $options['preload_settings']['preloadSitemap'] ) || ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
				return;
			}
			$url = get_permalink( $post_id );
			if ( ! is_string( $url ) || '' === $url ) {
				return;
			}
			if ( method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_excluded_url' ) ) {
				try {
					if ( Util::is_woo_excluded_url( $url ) ) {
						return;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					return;
				}
			} elseif ( $this->reject_legacy_woo_dynamic_url( $url, $options ) ) {
				return;
			}
			$url = esc_url_raw( $url );
			if ( '' !== $url ) {
				( $this->enqueue_unique_async_action )( 'wppo_crawler_warm', array( $url ), 'performance_optimisation' );
			}
		}

		/**
		 * Queue used-CSS generation when the setting is enabled.
		 *
		 * @since NEXT
		 * @internal Optional settings override is a focused test seam.
		 * @param int        $post_id Saved post ID.
		 * @param array|null $settings Optional settings snapshot.
		 * @return void
		 */
		public function queue_used_css_regeneration( int $post_id, ?array $settings = null ): void {
			$options = $settings ?? Util::get_settings();
			if ( empty( $options['file_optimisation']['removeUnusedCSS'] ) || ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
				return;
			}
			( $this->enqueue_unique_async_action )( 'wppo_used_css_generate', array( 'post_id' => $post_id ), 'performance_optimisation' );
		}

		/**
		 * Whether a runtime core enhancement buffer owns the legacy route.
		 *
		 * @since NEXT
		 * @return bool True when no legacy buffer may open.
		 */
		private function legacy_buffer_owned_by_core(): bool {
			try {
				if ( self::should_use_core_template_buffer() ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( function_exists( 'wp_should_output_buffer_template_for_enhancement' ) ) {
				try {
					return (bool) wp_should_output_buffer_template_for_enhancement();
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			return false;
		}

		/**
		 * Whether optimisation may run for the current viewer.
		 *
		 * @since NEXT
		 * @return bool True when frontend optimisation is eligible.
		 */
		private function should_optimise_for_logged_in(): bool {
			$options = ( $this->get_options )();
			return Util::is_cache_eligible_for_current_user( $options['cache_settings'] ?? array() );
		}

		/**
		 * Mixed-version fallback for rejecting dynamic Woo preload URLs.
		 *
		 * @since NEXT
		 * @param string $url     Candidate permalink.
		 * @param array  $options Resolved settings snapshot.
		 * @return bool True when the URL must not be scheduled.
		 */
		private function reject_legacy_woo_dynamic_url( string $url, array $options ): bool {
			try {
				$warm_path       = (string) wp_parse_url( $url, PHP_URL_PATH );
				$warm_qs         = (string) wp_parse_url( $url, PHP_URL_QUERY );
				$warm_rest_route = '';
				if ( '' !== $warm_qs ) {
					$warm_params = array();
					parse_str( $warm_qs, $warm_params );
					if ( isset( $warm_params['rest_route'] ) && is_string( $warm_params['rest_route'] ) ) {
						$warm_rest_route = $warm_params['rest_route'];
					}
				}
				if ( method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_store_api_request' ) ) {
					if ( Util::is_woo_store_api_request( $warm_path, $warm_qs, '' ) || ( '' !== $warm_rest_route && Util::is_woo_store_api_request( $warm_path, '', $warm_rest_route ) ) ) {
						return true;
					}
				} elseif ( method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_store_api_path' ) && ( Util::is_woo_store_api_path( $warm_path ) || ( '' !== $warm_rest_route && Util::is_woo_store_api_path( $warm_rest_route ) ) ) ) {
					return true;
				} elseif ( (bool) preg_match( '#(^|/)(?:wc/store|wcstore|wp-json/wc/store|wp-json/wcstore)(/|$)#i', '/' . ltrim( $warm_path, '/' ) ) || ( '' !== $warm_qs && (bool) preg_match( '#rest_route=[^&]*(?:wc/store|wcstore)#i', rawurldecode( $warm_qs ) ) ) ) {
					return true;
				}
				if ( method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_ajax_request' ) ) {
					if ( Util::is_woo_ajax_request( $warm_path, $warm_qs ) ) {
						return true;
					}
				} elseif ( (bool) preg_match( '#(^|/)wc-ajax(/|$)#i', '/' . ltrim( (string) rawurldecode( $warm_path ), '/' ) ) || ( '' !== $warm_qs && (bool) preg_match( '/(?:^|[&;])wc-ajax(?:=|&|;|$)/i', $warm_qs ) ) ) {
					return true;
				}
				if ( method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_faceted_query' ) ) {
					if ( '' !== $warm_qs && Util::is_woo_faceted_query( $warm_qs ) ) {
						return true;
					}
				} elseif ( '' !== $warm_qs && (bool) preg_match( '/(?:^|[&;])(?:filter_[^=&]*|query_type_[^=&]*|min_price|max_price|rating_filter|orderby|product_cat|pa_[^=&]*|attribute_[^=&]*|gpf_[^=&]*)(?:=|&|;|$)/i', $warm_qs ) ) {
					return true;
				}
				if ( method_exists( 'PerformanceOptimise\Inc\Util', 'has_uncacheable_query' ) ) {
					if ( '' !== $warm_qs && Util::has_uncacheable_query( $warm_qs ) ) {
						return true;
					}
				} elseif ( '' !== $warm_qs ) {
					return true;
				}
				if ( method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_dynamic_path' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_safe_mode_enabled' ) ) {
					if ( Util::is_woo_safe_mode_enabled( $options ) && Util::is_woo_dynamic_path( $warm_path ) ) {
						return true;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
			return false;
		}
	}
}
