<?php
/**
 * Modern CSS Optimizer
 *
 * Advanced CSS optimization with modern techniques including critical CSS extraction,
 * unused CSS removal, and intelligent minification.
 *
 * @package PerformanceOptimisation\Optimizers
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Optimizers;

use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Interfaces\OptimizerInterface;
use PerformanceOptimisation\Utils\LoggingUtil;
use PerformanceOptimisation\Utils\FileSystemUtil;
use PerformanceOptimisation\Utils\ValidationUtil;
use PerformanceOptimisation\Utils\PerformanceUtil;
use PerformanceOptimisation\Utils\CacheUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Modern CSS Optimizer Class
 */
class ModernCssOptimizer implements OptimizerInterface {

	/**
	 * Service container.
	 *
	 * @var ServiceContainerInterface
	 */
	private ServiceContainerInterface $container;

	/**
	 * Logger instance.
	 *
	 * @var LoggingUtil
	 */
	private LoggingUtil $logger;

	/**
	 * FileSystem utility.
	 *
	 * @var FileSystemUtil
	 */
	private FileSystemUtil $filesystem;

	/**
	 * Validator instance.
	 *
	 * @var ValidationUtil
	 */
	private ValidationUtil $validator;

	/**
	 * Performance utility.
	 *
	 * @var PerformanceUtil
	 */
	private PerformanceUtil $performance;

	/**
	 * Cache utility.
	 *
	 * @var CacheUtil
	 */
	private CacheUtil $cache;

	/**
	 * CSS parsing patterns.
	 *
	 * @var array
	 */
	private array $css_patterns = array();

	/**
	 * Critical CSS selectors.
	 *
	 * @var array
	 */
	private array $critical_selectors = array();

	/**
	 * Media query breakpoints.
	 *
	 * @var array
	 */
	private array $breakpoints = array(
		'mobile' => '(max-width: 767px)',
		'tablet' => '(min-width: 768px) and (max-width: 1023px)',
		'desktop' => '(min-width: 1024px)',
	);

	/**
	 * Constructor.
	 *
	 * @param ServiceContainerInterface $container Service container.
	 */
	public function __construct( ServiceContainerInterface $container ) {
		$this->container = $container;
		$this->logger = $container->get( 'logger' );
		$this->filesystem = $container->get( 'filesystem' );
		$this->validator = $container->get( 'validator' );
		$this->performance = $container->get( 'performance' );
		$this->cache = $container->get( 'cache' );
		
		$this->initializeCssPatterns();
		$this->initializeCriticalSelectors();
	}

	/**
	 * Optimize CSS content.
	 *
	 * @param string $css_content CSS content to optimize.
	 * @param array  $options     Optimization options.
	 * @return string Optimized CSS content.
	 */
	public function optimize( string $css_content, array $options = array() ): string {
		$timer_id = $this->performance->startTimer( 'css_optimization' );
		
		try {
			$original_size = strlen( $css_content );
			$this->logger->debug( 'Starting CSS optimization', array(
				'original_size' => $original_size,
				'options' => $options,
			) );

			// Generate cache key
			$cache_key = 'css_optimized_' . md5( $css_content . serialize( $options ) );
			
			// Check cache first
			$cached_result = $this->cache->get( $cache_key );
			if ( $cached_result !== null ) {
				$this->performance->endTimer( $timer_id );
				$this->logger->debug( 'CSS optimization result from cache', array( 'cache_key' => $cache_key ) );
				return $cached_result;
			}

			// Perform optimization steps
			$optimized_css = $css_content;
			$optimization_steps = array();

			// Step 1: Remove comments and normalize whitespace
			if ( $options['remove_comments'] ?? true ) {
				$optimized_css = $this->removeComments( $optimized_css );
				$optimization_steps[] = 'comments_removed';
			}

			// Step 2: Normalize and minify
			if ( $options['minify'] ?? true ) {
				$optimized_css = $this->minifyCss( $optimized_css );
				$optimization_steps[] = 'minified';
			}

			// Step 3: Optimize properties and values
			if ( $options['optimize_properties'] ?? true ) {
				$optimized_css = $this->optimizeProperties( $optimized_css );
				$optimization_steps[] = 'properties_optimized';
			}

			// Step 4: Optimize media queries
			if ( $options['optimize_media_queries'] ?? true ) {
				$optimized_css = $this->optimizeMediaQueries( $optimized_css );
				$optimization_steps[] = 'media_queries_optimized';
			}

			// Step 5: Remove unused CSS (if DOM provided)
			if ( isset( $options['html_content'] ) && ( $options['remove_unused'] ?? false ) ) {
				$optimized_css = $this->removeUnusedCss( $optimized_css, $options['html_content'] );
				$optimization_steps[] = 'unused_css_removed';
			}

			// Step 6: Extract critical CSS
			$critical_css = '';
			if ( isset( $options['html_content'] ) && ( $options['extract_critical'] ?? false ) ) {
				$critical_result = $this->extractCriticalCss( $optimized_css, $options['html_content'] );
				$critical_css = $critical_result['critical'];
				$optimized_css = $critical_result['remaining'];
				$optimization_steps[] = 'critical_css_extracted';
			}

			// Step 7: Optimize font declarations
			if ( $options['optimize_fonts'] ?? true ) {
				$optimized_css = $this->optimizeFonts( $optimized_css );
				$optimization_steps[] = 'fonts_optimized';
			}

			// Step 8: Optimize colors
			if ( $options['optimize_colors'] ?? true ) {
				$optimized_css = $this->optimizeColors( $optimized_css );
				$optimization_steps[] = 'colors_optimized';
			}

			$optimized_size = strlen( $optimized_css );
			$compression_ratio = $original_size > 0 ? ( ( $original_size - $optimized_size ) / $original_size ) * 100 : 0;

			$this->logger->info( 'CSS optimization completed', array(
				'original_size' => $original_size,
				'optimized_size' => $optimized_size,
				'compression_ratio' => $compression_ratio,
				'steps' => $optimization_steps,
			) );

			$this->performance->endTimer( $timer_id );
			return $optimized_css;

		} catch ( \Exception $e ) {
			$this->performance->endTimer( $timer_id );
			$this->logger->error( 'CSS optimization failed: ' . $e->getMessage() );
			
			return $css_content; // Return original content on error
		}
	}

	/**
	 * Optimize CSS file.
	 *
	 * @param string $file_path CSS file path.
	 * @param array  $options   Optimization options.
	 * @return array Optimization result.
	 */
	public function optimizeFile( string $file_path, array $options = array() ): array {
		try {
			if ( ! $this->filesystem->fileExists( $file_path ) ) {
				throw new \Exception( "CSS file not found: {$file_path}" );
			}

			$css_content = $this->filesystem->readFile( $file_path );
			$result = $this->optimize( $css_content, $options );

			if ( $result['success'] && isset( $options['save_optimized'] ) && $options['save_optimized'] ) {
				$optimized_path = $this->generateOptimizedPath( $file_path );
				$this->filesystem->writeFile( $optimized_path, $result['optimized_css'] );
				$result['optimized_path'] = $optimized_path;

				// Save critical CSS if extracted
				if ( ! empty( $result['critical_css'] ) ) {
					$critical_path = $this->generateCriticalPath( $file_path );
					$this->filesystem->writeFile( $critical_path, $result['critical_css'] );
					$result['critical_path'] = $critical_path;
				}
			}

			return $result;

		} catch ( \Exception $e ) {
			$this->logger->error( 'CSS file optimization failed', array(
				'file' => $file_path,
				'error' => $e->getMessage(),
			) );
			
			return array(
				'success' => false,
				'error' => $e->getMessage(),
				'file_path' => $file_path,
			);
		}
	}

	/**
	 * Combine multiple CSS files.
	 *
	 * @param array $file_paths Array of CSS file paths.
	 * @param array $options    Combination options.
	 * @return array Combination result.
	 */
	public function combineFiles( array $file_paths, array $options = array() ): array {
		$timer_id = $this->performance->startTimer( 'css_combination' );
		
		try {
			$combined_css = '';
			$file_info = array();
			$total_original_size = 0;

			foreach ( $file_paths as $file_path ) {
				if ( ! $this->filesystem->fileExists( $file_path ) ) {
					$this->logger->warning( 'CSS file not found during combination', array( 'file' => $file_path ) );
					continue;
				}

				$css_content = $this->filesystem->readFile( $file_path );
				$original_size = strlen( $css_content );
				$total_original_size += $original_size;

				// Add file header comment if enabled
				if ( $options['add_file_headers'] ?? true ) {
					$combined_css .= "/* File: " . basename( $file_path ) . " */\n";
				}

				// Optimize individual file if requested
				if ( $options['optimize_individual'] ?? true ) {
					$optimization_result = $this->optimize( $css_content, $options );
					if ( $optimization_result['success'] ) {
						$css_content = $optimization_result['optimized_css'];
					}
				}

				$combined_css .= $css_content . "\n";
				
				$file_info[] = array(
					'path' => $file_path,
					'original_size' => $original_size,
					'optimized_size' => strlen( $css_content ),
				);
			}

			// Final optimization of combined CSS
			if ( $options['optimize_combined'] ?? true ) {
				$final_optimization = $this->optimize( $combined_css, $options );
				if ( $final_optimization['success'] ) {
					$combined_css = $final_optimization['optimized_css'];
				}
			}

			$combined_size = strlen( $combined_css );
			$compression_ratio = $total_original_size > 0 ? ( ( $total_original_size - $combined_size ) / $total_original_size ) * 100 : 0;

			$result = array(
				'success' => true,
				'combined_css' => $combined_css,
				'file_count' => count( $file_info ),
				'total_original_size' => $total_original_size,
				'combined_size' => $combined_size,
				'compression_ratio' => round( $compression_ratio, 2 ),
				'file_info' => $file_info,
				'processing_time' => $this->performance->endTimer( $timer_id ),
			);

			// Save combined file if requested
			if ( isset( $options['output_path'] ) ) {
				$this->filesystem->writeFile( $options['output_path'], $combined_css );
				$result['output_path'] = $options['output_path'];
			}

			$this->logger->info( 'CSS files combined successfully', array(
				'file_count' => count( $file_info ),
				'total_original_size' => $total_original_size,
				'combined_size' => $combined_size,
				'compression_ratio' => $compression_ratio,
			) );

			return $result;

		} catch ( \Exception $e ) {
			$this->performance->endTimer( $timer_id );
			$this->logger->error( 'CSS combination failed: ' . $e->getMessage() );
			
			return array(
				'success' => false,
				'error' => $e->getMessage(),
				'file_paths' => $file_paths,
			);
		}
	}

	/**
	 * Remove CSS comments.
	 *
	 * @param string $css CSS content.
	 * @return string CSS without comments.
	 */
	private function removeComments( string $css ): string {
		// Remove /* */ comments but preserve important comments (/*! */)
		$css = preg_replace( '/\/\*(?!\!).*?\*\//s', '', $css );
		return $css;
	}

	/**
	 * Minify CSS content.
	 *
	 * @param string $css CSS content.
	 * @return string Minified CSS.
	 */
	private function minifyCss( string $css ): string {
		// Remove unnecessary whitespace
		$css = preg_replace( '/\s+/', ' ', $css );
		
		// Remove whitespace around specific characters
		$css = preg_replace( '/\s*([{}:;,>+~])\s*/', '$1', $css );
		
		// Remove trailing semicolon before closing brace
		$css = preg_replace( '/;+}/', '}', $css );
		
		// Remove empty rules
		$css = preg_replace( '/[^{}]+{\s*}/', '', $css );
		
		// Trim
		return trim( $css );
	}

	/**
	 * Optimize CSS properties and values.
	 *
	 * @param string $css CSS content.
	 * @return string Optimized CSS.
	 */
	private function optimizeProperties( string $css ): string {
		// Optimize margin and padding shorthand
		$css = preg_replace_callback( 
			'/(?:margin|padding):\s*([^;]+);/',
			array( $this, 'optimizeShorthand' ),
			$css
		);
		
		// Remove unnecessary quotes from font names
		$css = preg_replace( '/font-family:\s*["\']([^"\']+)["\']/i', 'font-family:$1', $css );
		
		// Optimize zero values
		$css = preg_replace( '/(?:^|[^0-9])0(?:px|em|rem|%|vh|vw|pt|pc|in|cm|mm|ex|ch|vmin|vmax)/', '0', $css );
		
		// Remove leading zeros
		$css = preg_replace( '/(?:^|[^0-9])0+\.([0-9]+)/', '.$1', $css );
		
		return $css;
	}

	/**
	 * Optimize media queries.
	 *
	 * @param string $css CSS content.
	 * @return string CSS with optimized media queries.
	 */
	private function optimizeMediaQueries( string $css ): string {
		// Extract and group media queries
		$media_queries = array();
		$css_without_media = $css;
		
		// Find all media queries
		preg_match_all( '/@media[^{]+\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/s', $css, $matches );
		
		foreach ( $matches[0] as $media_query ) {
			// Extract media condition
			preg_match( '/@media\s*([^{]+)\s*\{/', $media_query, $condition_match );
			$condition = trim( $condition_match[1] ?? '' );
			
			// Extract content
			$content = preg_replace( '/@media[^{]+\{/', '', $media_query );
			$content = rtrim( $content, '}' );
			
			if ( ! isset( $media_queries[ $condition ] ) ) {
				$media_queries[ $condition ] = '';
			}
			$media_queries[ $condition ] .= $content;
			
			// Remove from original CSS
			$css_without_media = str_replace( $media_query, '', $css_without_media );
		}
		
		// Rebuild CSS with grouped media queries
		$optimized_css = $css_without_media;
		foreach ( $media_queries as $condition => $content ) {
			$optimized_css .= "@media {$condition}{{$content}}";
		}
		
		return $optimized_css;
	}

	/**
	 * Remove unused CSS based on HTML content.
	 *
	 * @param string $css  CSS content.
	 * @param string $html HTML content.
	 * @return string CSS with unused rules removed.
	 */
	private function removeUnusedCss( string $css, string $html ): string {
		// Extract all CSS selectors
		preg_match_all( '/([^{}]+)\{[^{}]*\}/', $css, $matches );
		$used_css = '';
		
		foreach ( $matches[0] as $rule ) {
			preg_match( '/([^{]+)\{/', $rule, $selector_match );
			$selectors = explode( ',', $selector_match[1] ?? '' );
			$rule_used = false;
			
			foreach ( $selectors as $selector ) {
				$selector = trim( $selector );
				if ( $this->isSelectorUsed( $selector, $html ) ) {
					$rule_used = true;
					break;
				}
			}
			
			if ( $rule_used ) {
				$used_css .= $rule;
			}
		}
		
		return $used_css;
	}

	/**
	 * Extract critical CSS based on HTML content.
	 *
	 * @param string $css  CSS content.
	 * @param string $html HTML content.
	 * @return array Critical and remaining CSS.
	 */
	private function extractCriticalCss( string $css, string $html ): array {
		$critical_css = '';
		$remaining_css = '';
		
		// Extract all CSS rules
		preg_match_all( '/([^{}]+)\{[^{}]*\}/', $css, $matches );
		
		foreach ( $matches[0] as $rule ) {
			preg_match( '/([^{]+)\{/', $rule, $selector_match );
			$selectors = explode( ',', $selector_match[1] ?? '' );
			$is_critical = false;
			
			foreach ( $selectors as $selector ) {
				$selector = trim( $selector );
				if ( $this->isCriticalSelector( $selector ) || $this->isSelectorAboveFold( $selector, $html ) ) {
					$is_critical = true;
					break;
				}
			}
			
			if ( $is_critical ) {
				$critical_css .= $rule;
			} else {
				$remaining_css .= $rule;
			}
		}
		
		return array(
			'critical' => $critical_css,
			'remaining' => $remaining_css,
		);
	}

	/**
	 * Optimize font declarations.
	 *
	 * @param string $css CSS content.
	 * @return string CSS with optimized fonts.
	 */
	private function optimizeFonts( string $css ): string {
		// Add font-display: swap to @font-face rules
		$css = preg_replace( 
			'/(@font-face\s*\{[^}]*)(font-display\s*:\s*[^;]+;)?([^}]*\})/',
			'$1font-display:swap;$3',
			$css
		);
		
		// Optimize font-family declarations
		$css = preg_replace_callback(
			'/font-family\s*:\s*([^;]+);/',
			function( $matches ) {
				$fonts = explode( ',', $matches[1] );
				$optimized_fonts = array();
				
				foreach ( $fonts as $font ) {
					$font = trim( $font );
					// Remove unnecessary quotes
					$font = preg_replace( '/^["\']([^"\']+)["\']$/', '$1', $font );
					$optimized_fonts[] = $font;
				}
				
				return 'font-family:' . implode( ',', $optimized_fonts ) . ';';
			},
			$css
		);
		
		return $css;
	}

	/**
	 * Optimize color values.
	 *
	 * @param string $css CSS content.
	 * @return string CSS with optimized colors.
	 */
	private function optimizeColors( string $css ): string {
		// Convert long hex colors to short form
		$css = preg_replace( '/#([0-9a-f])\1([0-9a-f])\2([0-9a-f])\3/i', '#$1$2$3', $css );
		
		// Convert rgb() to hex when shorter
		$css = preg_replace_callback(
			'/rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/',
			function( $matches ) {
				$hex = sprintf( '#%02x%02x%02x', $matches[1], $matches[2], $matches[3] );
				return strlen( $hex ) <= strlen( $matches[0] ) ? $hex : $matches[0];
			},
			$css
		);
		
		return $css;
	}

	/**
	 * Check if a CSS selector is used in HTML.
	 *
	 * @param string $selector CSS selector.
	 * @param string $html     HTML content.
	 * @return bool True if selector is used, false otherwise.
	 */
	private function isSelectorUsed( string $selector, string $html ): bool {
		// Simplified selector usage detection
		// In a real implementation, you'd use a proper CSS selector parser
		
		// Check for ID selectors
		if ( preg_match( '/^#([a-zA-Z0-9_-]+)/', $selector, $matches ) ) {
			return strpos( $html, 'id="' . $matches[1] . '"' ) !== false;
		}
		
		// Check for class selectors
		if ( preg_match( '/^\.([a-zA-Z0-9_-]+)/', $selector, $matches ) ) {
			return strpos( $html, 'class="' . $matches[1] . '"' ) !== false ||
			       strpos( $html, 'class="' . $matches[1] . ' ' ) !== false ||
			       strpos( $html, ' ' . $matches[1] . '"' ) !== false;
		}
		
		// Check for element selectors
		if ( preg_match( '/^([a-zA-Z0-9]+)$/', $selector, $matches ) ) {
			return strpos( $html, '<' . $matches[1] ) !== false;
		}
		
		// For complex selectors, assume they're used (conservative approach)
		return true;
	}

	/**
	 * Check if a selector is critical (above the fold).
	 *
	 * @param string $selector CSS selector.
	 * @return bool True if critical, false otherwise.
	 */
	private function isCriticalSelector( string $selector ): bool {
		return in_array( trim( $selector ), $this->critical_selectors, true );
	}

	/**
	 * Check if a selector affects above-the-fold content.
	 *
	 * @param string $selector CSS selector.
	 * @param string $html     HTML content.
	 * @return bool True if above the fold, false otherwise.
	 */
	private function isSelectorAboveFold( string $selector, string $html ): bool {
		// Simplified above-the-fold detection
		// In a real implementation, you'd analyze the DOM structure and positioning
		
		$above_fold_elements = array( 'header', 'nav', 'h1', 'h2', '.hero', '#header', '.navbar' );
		
		foreach ( $above_fold_elements as $element ) {
			if ( strpos( $selector, $element ) !== false ) {
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Optimize CSS shorthand properties.
	 *
	 * @param array $matches Regex matches.
	 * @return string Optimized shorthand.
	 */
	private function optimizeShorthand( array $matches ): string {
		$values = preg_split( '/\s+/', trim( $matches[1] ) );
		$property = strpos( $matches[0], 'margin' ) !== false ? 'margin' : 'padding';
		
		// Optimize shorthand values
		if ( count( $values ) === 4 ) {
			// top right bottom left
			if ( $values[0] === $values[2] && $values[1] === $values[3] ) {
				if ( $values[0] === $values[1] ) {
					// All same
					return $property . ':' . $values[0] . ';';
				} else {
					// top/bottom left/right
					return $property . ':' . $values[0] . ' ' . $values[1] . ';';
				}
			}
		} elseif ( count( $values ) === 3 ) {
			// top left/right bottom
			if ( $values[0] === $values[2] ) {
				return $property . ':' . $values[0] . ' ' . $values[1] . ';';
			}
		} elseif ( count( $values ) === 2 ) {
			// top/bottom left/right
			if ( $values[0] === $values[1] ) {
				return $property . ':' . $values[0] . ';';
			}
		}
		
		return $matches[0]; // Return original if no optimization possible
	}

	/**
	 * Generate optimized file path.
	 *
	 * @param string $original_path Original file path.
	 * @return string Optimized file path.
	 */
	private function generateOptimizedPath( string $original_path ): string {
		$path_info = pathinfo( $original_path );
		return $path_info['dirname'] . '/' . $path_info['filename'] . '.min.' . $path_info['extension'];
	}

	/**
	 * Generate critical CSS file path.
	 *
	 * @param string $original_path Original file path.
	 * @return string Critical CSS file path.
	 */
	private function generateCriticalPath( string $original_path ): string {
		$path_info = pathinfo( $original_path );
		return $path_info['dirname'] . '/' . $path_info['filename'] . '.critical.' . $path_info['extension'];
	}

	/**
	 * Initialize CSS parsing patterns.
	 */
	private function initializeCssPatterns(): void {
		$this->css_patterns = array(
			'selector' => '/([^{}]+)\{([^{}]*)\}/',
			'property' => '/([^:]+):\s*([^;]+);?/',
			'media_query' => '/@media\s*([^{]+)\s*\{((?:[^{}]*\{[^{}]*\})*[^{}]*)\}/',
			'import' => '/@import\s+(?:url\()?["\']?([^"\'()]+)["\']?\)?[^;]*;/',
			'font_face' => '/@font-face\s*\{([^}]+)\}/',
		);
	}

	/**
	 * Initialize critical CSS selectors.
	 */
	private function initializeCriticalSelectors(): void {
		$this->critical_selectors = array(
			'html',
			'body',
			'header',
			'nav',
			'h1',
			'h2',
			'.header',
			'.navbar',
			'.hero',
			'.banner',
			'.logo',
			'.menu',
			'.navigation',
			'#header',
			'#navbar',
			'#hero',
			'#banner',
		);
	}

	/**
	 * Check if optimizer can handle the content type.
	 *
	 * @param string $content_type Content type.
	 * @return bool True if can handle, false otherwise.
	 */
	public function can_optimize( string $content_type ): bool {
		return $content_type === 'css';
	}

	/**
	 * Get optimizer name.
	 *
	 * @return string Optimizer name.
	 */
	public function get_name(): string {
		return 'Modern CSS Optimizer';
	}

	/**
	 * Get supported content types.
	 *
	 * @return array Array of supported content types.
	 */
	public function get_supported_types(): array {
		return array( 'css' );
	}

	/**
	 * Get optimization statistics.
	 *
	 * @return array Optimization statistics.
	 */
	public function get_stats(): array {
		return array(
			'files_optimized' => 0,
			'bytes_saved' => 0,
			'compression_ratio' => 0,
		);
	}

	/**
	 * Reset optimization statistics.
	 *
	 * @return void
	 */
	public function reset_stats(): void {
		// Implementation for resetting stats
	}
}