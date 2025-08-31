<?php
/**
 * HTML Optimizer
 *
 * @package PerformanceOptimisation\Optimizers
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Optimizers;

use PerformanceOptimisation\Interfaces\OptimizerInterface;
use PerformanceOptimisation\Utils\CacheUtil;
use PerformanceOptimisation\Utils\FileSystemUtil;
use PerformanceOptimisation\Utils\LoggingUtil;
use PerformanceOptimisation\Utils\PerformanceUtil;
use PerformanceOptimisation\Utils\ValidationUtil;
use voku\helper\HtmlMin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HtmlOptimizer implements OptimizerInterface {

	private array $stats = array(
		'total_files'   => 0,
		'total_bytes'   => 0,
		'bytes_saved'   => 0,
		'total_time_ms' => 0,
	);

	private array $optimization_options = array(
		'enable_minification' => true,
		'remove_comments' => true,
		'remove_whitespace' => true,
		'optimize_attributes' => true,
		'generate_resource_hints' => true,
		'optimize_images' => true,
		'lazy_load_images' => true,
		'lazy_load_iframes' => true,
		'preload_critical_resources' => true,
		'optimize_forms' => true,
		'remove_empty_elements' => true,
		'preserve_line_breaks' => false,
	);

	public function optimize( string $content, array $options = array() ): string {
		$original_size = strlen( $content );
		
		// Performance tracking
		PerformanceUtil::startTimer( 'html_optimization' );
		
		try {
			// Merge options with defaults
			$options = array_merge( $this->optimization_options, $options );
			
			// Validate HTML content
			$content = ValidationUtil::sanitizeHtml( $content );
			
			// Apply optimization techniques
			$optimized = $this->minify_html( $content, $options );
			$optimized = $this->optimize_images( $optimized, $options );
			$optimized = $this->optimize_forms( $optimized, $options );
			$optimized = $this->generate_resource_hints( $optimized, $options );
			$optimized = $this->optimize_attributes( $optimized, $options );
			$optimized = $this->remove_empty_elements( $optimized, $options );
			
			$optimized_size = strlen( $optimized );
			$compression_ratio = $original_size > 0 ? ( 1 - $optimized_size / $original_size ) : 0;
			$duration = PerformanceUtil::endTimer( 'html_optimization' );
			
			// Update stats
			++$this->stats['total_files'];
			$this->stats['total_bytes'] += $original_size;
			$this->stats['bytes_saved'] += ( $original_size - $optimized_size );
			$this->stats['total_time_ms'] += $duration * 1000;
			
			LoggingUtil::info( 'HTML optimized successfully', array(
				'original_size' => $original_size,
				'optimized_size' => $optimized_size,
				'compression_ratio' => round( $compression_ratio * 100, 2 ) . '%',
				'duration' => $duration,
			) );
			
			return $optimized;
			
		} catch ( \Exception $e ) {
			PerformanceUtil::endTimer( 'html_optimization' );
			LoggingUtil::error( 'HTML optimization failed: ' . $e->getMessage() );
			return $content; // Return original content on failure
		}
	}

	public function can_optimize( string $content_type ): bool {
		return in_array( $content_type, $this->get_supported_types(), true );
	}

	public function get_name(): string {
		return 'HTML Optimizer';
	}

	public function get_supported_types(): array {
		return array( 'html' );
	}

	public function get_stats(): array {
		return $this->stats;
	}

	public function reset_stats(): void {
		$this->stats = array(
			'total_files'   => 0,
			'total_bytes'   => 0,
			'bytes_saved'   => 0,
			'total_time_ms' => 0,
		);
	}

	/**
	 * Process HTML file with caching.
	 *
	 * @param string $file_path HTML file path.
	 * @param array  $options   Optimization options.
	 * @return string Optimized HTML content.
	 */
	public function process_file( string $file_path, array $options = array() ): string {
		if ( ! FileSystemUtil::fileExists( $file_path ) ) {
			LoggingUtil::warning( 'HTML file not found for processing', array( 'path' => $file_path ) );
			return '';
		}
		
		// Check cache first
		$cache_key = CacheUtil::generateCacheKey( $file_path . filemtime( $file_path ), 'html_opt' );
		$cached_result = wp_cache_get( $cache_key, 'wppo_html_optimization' );
		
		if ( false !== $cached_result ) {
			LoggingUtil::debug( 'HTML optimization served from cache', array( 'path' => $file_path ) );
			return $cached_result;
		}
		
		try {
			$content = FileSystemUtil::readFile( $file_path );
			$optimized = $this->optimize( $content, $options );
			
			// Cache the result
			wp_cache_set( $cache_key, $optimized, 'wppo_html_optimization', CacheUtil::getCacheExpiry( 'minified' ) );
			
			return $optimized;
			
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Failed to process HTML file: ' . $e->getMessage(), array( 'path' => $file_path ) );
			return '';
		}
	}

	/**
	 * Minify HTML content using advanced techniques.
	 *
	 * @param string $html    HTML content.
	 * @param array  $options Minification options.
	 * @return string Minified HTML.
	 */
	private function minify_html( string $html, array $options ): string {
		if ( ! $options['enable_minification'] ) {
			return $html;
		}

		try {
			$htmlMin = new HtmlMin();
			
			// Configure minifier options
			$htmlMin->doOptimizeViaHtmlDomParser( true );
			$htmlMin->doRemoveComments( $options['remove_comments'] );
			$htmlMin->doSumUpWhitespace( $options['remove_whitespace'] );
			$htmlMin->doRemoveWhitespaceAroundTags( $options['remove_whitespace'] );
			$htmlMin->doOptimizeAttributes( $options['optimize_attributes'] );
			$htmlMin->doRemoveHttpPrefixFromAttributes( true );
			$htmlMin->doRemoveDefaultAttributes( true );
			$htmlMin->doRemoveDeprecatedAnchorName( true );
			$htmlMin->doRemoveDeprecatedScriptCharsetAttribute( true );
			$htmlMin->doRemoveDeprecatedTypeFromScriptTag( true );
			$htmlMin->doRemoveDeprecatedTypeFromStylesheetLink( true );
			$htmlMin->doRemoveEmptyAttributes( true );
			$htmlMin->doRemoveValueFromEmptyInput( true );
			$htmlMin->doSortCssClassNames( true );
			$htmlMin->doSortHtmlAttributes( true );
			
			return $htmlMin->minify( $html );
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'HTML minification failed: ' . $e->getMessage() );
			return $html;
		}
	}

	/**
	 * Optimize images in HTML content.
	 *
	 * @param string $html    HTML content.
	 * @param array  $options Optimization options.
	 * @return string Optimized HTML.
	 */
	private function optimize_images( string $html, array $options ): string {
		if ( ! $options['optimize_images'] ) {
			return $html;
		}

		// Add lazy loading to images
		if ( $options['lazy_load_images'] ) {
			$html = $this->add_lazy_loading_images( $html );
		}

		// Add lazy loading to iframes
		if ( $options['lazy_load_iframes'] ) {
			$html = $this->add_lazy_loading_iframes( $html );
		}

		// Optimize image attributes
		$html = $this->optimize_image_attributes( $html );

		// Add responsive image attributes
		$html = $this->add_responsive_images( $html );

		return $html;
	}

	/**
	 * Add lazy loading to images.
	 *
	 * @param string $html HTML content.
	 * @return string HTML with lazy loading images.
	 */
	private function add_lazy_loading_images( string $html ): string {
		// Skip images that already have loading attribute or are in critical areas
		$pattern = '/<img(?![^>]*loading=)(?![^>]*class="[^"]*no-lazy)([^>]*?)src="([^"]*)"([^>]*?)>/i';
		
		return preg_replace_callback( $pattern, function( $matches ) {
			$before_src = $matches[1];
			$src = $matches[2];
			$after_src = $matches[3];
			
			// Skip data URLs and very small images
			if ( strpos( $src, 'data:' ) === 0 || $this->is_small_image( $src ) ) {
				return $matches[0];
			}
			
			// Add loading="lazy" and data-src for intersection observer
			$optimized = '<img' . $before_src . ' loading="lazy" data-src="' . $src . '" src="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 1 1\'%3E%3C/svg%3E"' . $after_src . '>';
			
			return $optimized;
		}, $html );
	}

	/**
	 * Add lazy loading to iframes.
	 *
	 * @param string $html HTML content.
	 * @return string HTML with lazy loading iframes.
	 */
	private function add_lazy_loading_iframes( string $html ): string {
		$pattern = '/<iframe(?![^>]*loading=)([^>]*?)src="([^"]*)"([^>]*?)>/i';
		
		return preg_replace_callback( $pattern, function( $matches ) {
			$before_src = $matches[1];
			$src = $matches[2];
			$after_src = $matches[3];
			
			// Add loading="lazy" and data-src
			return '<iframe' . $before_src . ' loading="lazy" data-src="' . $src . '"' . $after_src . '>';
		}, $html );
	}

	/**
	 * Optimize image attributes.
	 *
	 * @param string $html HTML content.
	 * @return string HTML with optimized image attributes.
	 */
	private function optimize_image_attributes( string $html ): string {
		// Add missing alt attributes
		$html = preg_replace( '/<img(?![^>]*alt=)([^>]*?)>/i', '<img$1 alt="">', $html );
		
		// Add decoding="async" for better performance
		$html = preg_replace( '/<img(?![^>]*decoding=)([^>]*?)>/i', '<img$1 decoding="async">', $html );
		
		return $html;
	}

	/**
	 * Add responsive image attributes.
	 *
	 * @param string $html HTML content.
	 * @return string HTML with responsive image attributes.
	 */
	private function add_responsive_images( string $html ): string {
		// This is a simplified version - in practice, you'd generate actual srcset values
		$pattern = '/<img(?![^>]*srcset=)([^>]*?)src="([^"]*?\.(?:jpg|jpeg|png|webp))"([^>]*?)>/i';
		
		return preg_replace_callback( $pattern, function( $matches ) {
			$before_src = $matches[1];
			$src = $matches[2];
			$after_src = $matches[3];
			
			// Generate responsive image srcset (simplified)
			$srcset = $this->generate_srcset( $src );
			
			if ( ! empty( $srcset ) ) {
				return '<img' . $before_src . 'src="' . $src . '" srcset="' . $srcset . '" sizes="(max-width: 768px) 100vw, 50vw"' . $after_src . '>';
			}
			
			return $matches[0];
		}, $html );
	}

	/**
	 * Optimize form elements.
	 *
	 * @param string $html    HTML content.
	 * @param array  $options Optimization options.
	 * @return string Optimized HTML.
	 */
	private function optimize_forms( string $html, array $options ): string {
		if ( ! $options['optimize_forms'] ) {
			return $html;
		}

		// Add autocomplete attributes for better UX
		$html = $this->add_autocomplete_attributes( $html );
		
		// Optimize input types
		$html = $this->optimize_input_types( $html );
		
		// Add form validation attributes
		$html = $this->add_validation_attributes( $html );

		return $html;
	}

	/**
	 * Generate resource hints in HTML head.
	 *
	 * @param string $html    HTML content.
	 * @param array  $options Optimization options.
	 * @return string HTML with resource hints.
	 */
	private function generate_resource_hints( string $html, array $options ): string {
		if ( ! $options['generate_resource_hints'] ) {
			return $html;
		}

		$resource_hints = $this->extract_resource_hints( $html );
		
		if ( empty( $resource_hints ) ) {
			return $html;
		}

		$hints_html = $this->build_resource_hints_html( $resource_hints );
		
		// Insert resource hints in head
		$html = preg_replace( '/(<head[^>]*>)/i', '$1' . "\n" . $hints_html, $html );

		return $html;
	}

	/**
	 * Optimize HTML attributes.
	 *
	 * @param string $html    HTML content.
	 * @param array  $options Optimization options.
	 * @return string Optimized HTML.
	 */
	private function optimize_attributes( string $html, array $options ): string {
		if ( ! $options['optimize_attributes'] ) {
			return $html;
		}

		// Remove redundant attributes
		$html = preg_replace( '/\s+type="text\/javascript"/i', '', $html );
		$html = preg_replace( '/\s+type="text\/css"/i', '', $html );
		
		// Optimize boolean attributes
		$html = preg_replace( '/\s+(checked|selected|disabled|readonly|multiple|autofocus|autoplay|controls|defer|hidden|loop|muted|open|required|reversed)="[^"]*"/i', ' $1', $html );
		
		// Remove empty attributes
		$html = preg_replace( '/\s+[a-zA-Z-]+=""\s*/', ' ', $html );

		return $html;
	}

	/**
	 * Remove empty HTML elements.
	 *
	 * @param string $html    HTML content.
	 * @param array  $options Optimization options.
	 * @return string HTML without empty elements.
	 */
	private function remove_empty_elements( string $html, array $options ): string {
		if ( ! $options['remove_empty_elements'] ) {
			return $html;
		}

		// Remove empty paragraphs
		$html = preg_replace( '/<p[^>]*>\\s*<\/p>/i', '', $html );
		
		// Remove empty divs (but preserve those with classes or IDs)
		$html = preg_replace( '/<div>\\s*<\/div>/i', '', $html );
		
		// Remove empty spans
		$html = preg_replace( '/<span[^>]*>\\s*<\/span>/i', '', $html );

		return $html;
	}

	/**
	 * Check if image is small and shouldn't be lazy loaded.
	 *
	 * @param string $src Image source URL.
	 * @return bool True if image is small, false otherwise.
	 */
	private function is_small_image( string $src ): bool {
		// Check for common small image patterns
		$small_patterns = array(
			'/\\d+x\\d+/', // Dimensions in filename
			'/thumb/', // Thumbnail images
			'/icon/', // Icon images
			'/logo/', // Logo images (usually small)
		);

		foreach ( $small_patterns as $pattern ) {
			if ( preg_match( $pattern, $src ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Generate srcset for responsive images.
	 *
	 * @param string $src Original image source.
	 * @return string Srcset attribute value.
	 */
	private function generate_srcset( string $src ): string {
		// This is a simplified implementation
		// In practice, you'd check for actual responsive image files
		$base_url = dirname( $src );
		$filename = pathinfo( $src, PATHINFO_FILENAME );
		$extension = pathinfo( $src, PATHINFO_EXTENSION );

		$srcset_candidates = array();
		$sizes = array( 320, 640, 768, 1024, 1200 );

		foreach ( $sizes as $size ) {
			$responsive_url = $base_url . '/' . $filename . '-' . $size . 'w.' . $extension;
			$srcset_candidates[] = $responsive_url . ' ' . $size . 'w';
		}

		return implode( ', ', $srcset_candidates );
	}

	/**
	 * Add autocomplete attributes to form inputs.
	 *
	 * @param string $html HTML content.
	 * @return string HTML with autocomplete attributes.
	 */
	private function add_autocomplete_attributes( string $html ): string {
		$autocomplete_map = array(
			'email' => 'email',
			'password' => 'current-password',
			'name' => 'name',
			'firstname' => 'given-name',
			'lastname' => 'family-name',
			'phone' => 'tel',
			'address' => 'street-address',
			'city' => 'address-level2',
			'zip' => 'postal-code',
			'country' => 'country-name',
		);

		foreach ( $autocomplete_map as $name_pattern => $autocomplete_value ) {
			$pattern = '/<input(?![^>]*autocomplete=)([^>]*?)name="[^"]*' . $name_pattern . '[^"]*"([^>]*?)>/i';
			$html = preg_replace( $pattern, '<input$1name="$2" autocomplete="' . $autocomplete_value . '"$3>', $html );
		}

		return $html;
	}

	/**
	 * Optimize input types for better mobile experience.
	 *
	 * @param string $html HTML content.
	 * @return string HTML with optimized input types.
	 */
	private function optimize_input_types( string $html ): string {
		$type_map = array(
			'email' => 'email',
			'phone' => 'tel',
			'url' => 'url',
			'number' => 'number',
			'date' => 'date',
		);

		foreach ( $type_map as $name_pattern => $input_type ) {
			$pattern = '/<input(?![^>]*type="' . $input_type . '")([^>]*?)name="[^"]*' . $name_pattern . '[^"]*"([^>]*?)>/i';
			$html = preg_replace( $pattern, '<input type="' . $input_type . '"$1name="$2"$3>', $html );
		}

		return $html;
	}

	/**
	 * Add validation attributes to form inputs.
	 *
	 * @param string $html HTML content.
	 * @return string HTML with validation attributes.
	 */
	private function add_validation_attributes( string $html ): string {
		// Add required attribute to inputs that look required
		$required_patterns = array( 'required', 'mandatory', '*' );
		
		foreach ( $required_patterns as $pattern ) {
			$regex = '/<input(?![^>]*required)([^>]*?)(?:name|placeholder)="[^"]*' . preg_quote( $pattern, '/' ) . '[^"]*"([^>]*?)>/i';
			$html = preg_replace( $regex, '<input$1$2 required>', $html );
		}

		return $html;
	}

	/**
	 * Extract resources for generating hints.
	 *
	 * @param string $html HTML content.
	 * @return array Resource hints data.
	 */
	private function extract_resource_hints( string $html ): array {
		$hints = array(
			'preload' => array(),
			'prefetch' => array(),
			'dns-prefetch' => array(),
			'preconnect' => array(),
		);

		// Extract critical CSS files for preload
		preg_match_all( '/<link[^>]*rel="stylesheet"[^>]*href="([^"]*)"[^>]*>/i', $html, $css_matches );
		foreach ( $css_matches[1] as $css_url ) {
			if ( $this->is_critical_resource( $css_url ) ) {
				$hints['preload'][] = array(
					'href' => $css_url,
					'as' => 'style',
				);
			}
		}

		// Extract critical JavaScript files for preload
		preg_match_all( '/<script[^>]*src="([^"]*)"[^>]*>/i', $html, $js_matches );
		foreach ( $js_matches[1] as $js_url ) {
			if ( $this->is_critical_resource( $js_url ) ) {
				$hints['preload'][] = array(
					'href' => $js_url,
					'as' => 'script',
				);
			}
		}

		// Extract external domains for DNS prefetch
		$all_urls = array_merge( $css_matches[1], $js_matches[1] );
		foreach ( $all_urls as $url ) {
			$domain = $this->extract_domain( $url );
			if ( $domain && $domain !== $_SERVER['HTTP_HOST'] ) {
				$hints['dns-prefetch'][] = $domain;
			}
		}

		return $hints;
	}

	/**
	 * Build resource hints HTML.
	 *
	 * @param array $hints Resource hints data.
	 * @return string Resource hints HTML.
	 */
	private function build_resource_hints_html( array $hints ): string {
		$html = '';

		// DNS prefetch hints
		foreach ( array_unique( $hints['dns-prefetch'] ) as $domain ) {
			$html .= '<link rel="dns-prefetch" href="//' . esc_attr( $domain ) . '">' . "\n";
		}

		// Preload hints
		foreach ( $hints['preload'] as $resource ) {
			$html .= '<link rel="preload" href="' . esc_attr( $resource['href'] ) . '" as="' . esc_attr( $resource['as'] ) . '">' . "\n";
		}

		return $html;
	}

	/**
	 * Check if resource is critical and should be preloaded.
	 *
	 * @param string $url Resource URL.
	 * @return bool True if critical, false otherwise.
	 */
	private function is_critical_resource( string $url ): bool {
		$critical_patterns = array(
			'/critical/',
			'/above-fold/',
			'/main\\.css/',
			'/style\\.css/',
			'/app\\.js/',
			'/main\\.js/',
		);

		foreach ( $critical_patterns as $pattern ) {
			if ( preg_match( $pattern, $url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract domain from URL.
	 *
	 * @param string $url URL to extract domain from.
	 * @return string|null Domain or null if not external.
	 */
	private function extract_domain( string $url ): ?string {
		if ( strpos( $url, '//' ) === 0 ) {
			return parse_url( 'http:' . $url, PHP_URL_HOST );
		}
		
		if ( strpos( $url, 'http' ) === 0 ) {
			return parse_url( $url, PHP_URL_HOST );
		}

		return null; // Relative URL
	}

	/**
	 * Extract critical above-the-fold HTML.
	 *
	 * @param string $html     Full HTML content.
	 * @param int    $fold_height Fold height in pixels.
	 * @return string Critical HTML.
	 */
	public function extractCriticalHTML( string $html, int $fold_height = 600 ): string {
		// This is a simplified implementation
		// In practice, you'd use more sophisticated techniques to determine critical content
		
		$critical_selectors = array(
			'header',
			'nav',
			'.hero',
			'.banner',
			'h1',
			'.above-fold',
			'.critical',
		);

		$critical_html = '';
		
		foreach ( $critical_selectors as $selector ) {
			$pattern = '/<' . preg_quote( $selector, '/' ) . '[^>]*>.*?<\/' . preg_quote( $selector, '/' ) . '>/s';
			if ( preg_match( $pattern, $html, $matches ) ) {
				$critical_html .= $matches[0] . "\n";
			}
		}

		return $this->optimize( $critical_html );
	}

	/**
	 * Generate structured data optimization.
	 *
	 * @param string $html HTML content.
	 * @return string HTML with optimized structured data.
	 */
	public function optimizeStructuredData( string $html ): string {
		// Extract and optimize JSON-LD structured data
		$pattern = '/<script[^>]*type="application\/ld\+json"[^>]*>(.*?)<\/script>/s';
		
		return preg_replace_callback( $pattern, function( $matches ) {
			$json_data = $matches[1];
			
			try {
				// Decode, optimize, and re-encode JSON
				$data = json_decode( $json_data, true );
				if ( $data ) {
					$optimized_json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
					return str_replace( $json_data, $optimized_json, $matches[0] );
				}
			} catch ( \Exception $e ) {
				LoggingUtil::error( 'Failed to optimize structured data: ' . $e->getMessage() );
			}
			
			return $matches[0];
		}, $html );
	}
}
