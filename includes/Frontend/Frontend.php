<?php
/**
 * Frontend Class
 *
 * @package PerformanceOptimisation\Frontend
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Frontend;

use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Services\CacheService;
use PerformanceOptimisation\Services\PageCacheService;
use PerformanceOptimisation\Services\ImageService;
use PerformanceOptimisation\Services\OptimizationService;
use PerformanceOptimisation\Services\SettingsService;
use PerformanceOptimisation\Utils\LoggingUtil;
use PerformanceOptimisation\Utils\PerformanceUtil;
use PerformanceOptimisation\Utils\ValidationUtil;
use PerformanceOptimisation\Admin\Metabox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Frontend
 *
 * @package PerformanceOptimisation\Frontend
 */
class Frontend {

	private ServiceContainerInterface $container;
	private CacheService $cacheService;
	private ?PageCacheService $pageCacheService = null;
	private ImageService $imageService;
	private OptimizationService $optimizationService;
	private SettingsService $settingsService;
	private LoggingUtil $logger;
	private PerformanceUtil $performance;
	private ValidationUtil $validator;
	private Metabox $metabox;

	public function __construct( ServiceContainerInterface $container ) {
		error_log( 'WPPO: Frontend __construct called' );

		$this->container           = $container;
		$this->cacheService        = $container->get( 'cache_service' );
		$this->imageService        = $container->get( 'image_service' );
		$this->optimizationService = $container->get( 'optimization_service' );
		$this->settingsService     = $container->get( 'settings_service' );
		$this->logger              = $container->get( 'logger' );
		$this->performance         = $container->get( 'performance' );
		$this->validator           = $container->get( 'validator' );
		$this->metabox             = $container->get( 'metabox' );

		// Initialize PageCacheService - this will set up caching hooks
		try {
			$service = $container->get( 'PerformanceOptimisation\\Services\\PageCacheService' );
			// If we got a Closure, call it to get the actual service
			if ( $service instanceof \Closure ) {
				$this->pageCacheService = $service( $container );
			} else {
				$this->pageCacheService = $service;
			}
			error_log( 'WPPO: PageCacheService initialized in Frontend' );
		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to initialize PageCacheService: ' . $e->getMessage() );
		}
	}

	public function setup_hooks(): void {
		// Core frontend hooks
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
		add_action( 'wp_head', array( $this, 'add_preload_prefetch_preconnect_links' ), 1 );
		add_action( 'wp_head', array( $this, 'add_critical_css' ), 2 );
		add_action( 'wp_head', array( $this, 'add_performance_hints' ), 3 );

		// Script and style modification
		add_filter( 'script_loader_tag', array( $this, 'modify_script_loader_tag' ), 20, 3 );
		add_filter( 'style_loader_tag', array( $this, 'modify_style_loader_tag' ), 20, 3 );

		// Image optimization hooks
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'add_lazy_loading_attributes' ), 10, 3 );
		add_filter( 'the_content', array( $this, 'optimize_content_images' ), 999 );

		// Performance monitoring
		add_action( 'wp_footer', array( $this, 'add_performance_monitoring' ), 999 );

		// Conditional hooks based on settings
		if ( $this->settingsService->get_setting( 'file_optimisation', 'removeWooCSSJS' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'conditionally_remove_woocommerce_assets' ), 999 );
		}

		if ( $this->settingsService->get_setting( 'preload_settings', 'enablePreloadCache' ) ) {
			add_action( 'template_redirect', array( $this->cacheService, 'generate_dynamic_static_html' ), 5 );
		}

		if ( $this->settingsService->get_setting( 'file_optimisation', 'combineCSS' ) ) {
			add_action( 'wp_print_styles', array( $this, 'handle_css_combination' ), PHP_INT_MAX - 10 );
		}

		if ( $this->settingsService->get_setting( 'file_optimisation', 'combineJS' ) ) {
			add_action( 'wp_print_scripts', array( $this, 'handle_js_combination' ), PHP_INT_MAX - 10 );
		}

		// HTML optimization
		if ( $this->settingsService->get_setting( 'html_optimisation', 'enable_html_minification' ) ) {
			add_action( 'template_redirect', array( $this, 'start_html_optimization' ), 1 );
		}

		$this->logger->debug( 'Frontend hooks setup completed' );
	}

	public function enqueue_frontend_scripts(): void {
		if ( is_admin() ) {
			return;
		}

		$this->performance->startTimer( 'frontend_script_enqueue' );

		// Lazy loading script
		if ( $this->settingsService->get_setting( 'image_optimisation', 'lazy_loading' ) ) {
			$this->enqueueLazyLoadScript();
		}

		// Performance monitoring script (only for logged-in users with capability)
		if ( current_user_can( 'manage_options' ) && $this->settingsService->get_setting( 'performance', 'enable_frontend_monitoring' ) ) {
			$this->enqueuePerformanceMonitoringScript();
		}

		// Critical resource preloader
		if ( $this->settingsService->get_setting( 'preload_settings', 'enable_critical_resource_preloader' ) ) {
			$this->enqueueCriticalResourcePreloader();
		}

		$duration = $this->performance->endTimer( 'frontend_script_enqueue' );
		$this->logger->debug( 'Frontend scripts enqueued', array( 'duration' => $duration ) );
	}

	/**
	 * Enqueue lazy loading script with enhanced features.
	 */
	private function enqueueLazyLoadScript(): void {
		$asset_file_path = WPPO_PLUGIN_PATH . 'build/lazyload.asset.php';
		$asset           = file_exists( $asset_file_path ) ? require $asset_file_path : array(
			'dependencies' => array(),
			'version'      => WPPO_VERSION,
		);

		wp_enqueue_script(
			'wppo-lazyload',
			WPPO_PLUGIN_URL . 'build/lazyload.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Localize script with configuration
		wp_localize_script(
			'wppo-lazyload',
			'wppoLazyLoad',
			array(
				'threshold'               => $this->settingsService->get_setting( 'image_optimisation', 'lazy_loading_threshold' ) ?? 300,
				'enableNativeLazyLoading' => $this->settingsService->get_setting( 'image_optimisation', 'enable_native_lazy_loading' ) ?? true,
				'placeholderSrc'          => $this->getPlaceholderImageSrc(),
				'fadeInDuration'          => $this->settingsService->get_setting( 'image_optimisation', 'fade_in_duration' ) ?? 300,
			)
		);
	}

	/**
	 * Enqueue performance monitoring script.
	 */
	private function enqueuePerformanceMonitoringScript(): void {
		wp_enqueue_script(
			'wppo-performance-monitor',
			WPPO_PLUGIN_URL . 'build/performance-monitor.js',
			array(),
			WPPO_VERSION,
			true
		);

		wp_localize_script(
			'wppo-performance-monitor',
			'wppoPerformance',
			array(
				'apiUrl'   => rest_url( 'wppo/v1/performance' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'pageId'   => get_queried_object_id(),
				'pageType' => $this->getCurrentPageType(),
			)
		);
	}

	/**
	 * Enqueue critical resource preloader.
	 */
	private function enqueueCriticalResourcePreloader(): void {
		wp_enqueue_script(
			'wppo-resource-preloader',
			WPPO_PLUGIN_URL . 'build/resource-preloader.js',
			array(),
			WPPO_VERSION,
			true
		);

		$critical_resources = $this->getCriticalResources();

		wp_localize_script(
			'wppo-resource-preloader',
			'wppoPreloader',
			array(
				'resources'       => $critical_resources,
				'preloadStrategy' => $this->settingsService->get_setting( 'preload_settings', 'preload_strategy' ) ?? 'intersection',
			)
		);
	}

	public function add_preload_prefetch_preconnect_links(): void {
		if ( is_admin() ) {
			return;
		}

		$this->performance->startTimer( 'resource_hints_generation' );

		// Add global resource hints from settings
		$this->addGlobalResourceHints();

		// Add page-specific preload URLs from metabox
		$this->addPageSpecificPreloadUrls();

		// Add automatic resource hints based on page content
		$this->addAutomaticResourceHints();

		$duration = $this->performance->endTimer( 'resource_hints_generation' );
		$this->logger->debug( 'Resource hints generated', array( 'duration' => $duration ) );
	}

	/**
	 * Add global resource hints from settings.
	 */
	private function addGlobalResourceHints(): void {
		$settings   = $this->settingsService->get_setting( 'preload_settings', '' );
		$link_types = array(
			'preconnect'   => 'preconnectOrigins',
			'dns-prefetch' => 'dnsPrefetchOrigins',
			'preload'      => 'preloadFontsUrls',
			'preload'      => 'preloadCSSUrls',
		);

		foreach ( $link_types as $rel => $setting_key ) {
			if ( ! empty( $settings[ $setting_key ] ) ) {
				$urls = $this->validator->processUrls( $settings[ $setting_key ] );
				foreach ( $urls as $url ) {
					$this->outputResourceHint( $rel, $url, $this->getResourceType( $url ) );
				}
			}
		}
	}

	/**
	 * Add page-specific preload URLs from metabox.
	 */
	private function addPageSpecificPreloadUrls(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id     = get_queried_object_id();
		$device_type = $this->detectDeviceType();

		// Get device-specific URLs
		$preload_urls = $this->metabox->getDeviceSpecificUrls( $post_id, $device_type );

		foreach ( $preload_urls as $url ) {
			$validated_url = $this->validator->sanitizeUrl( $url );
			if ( ! empty( $validated_url ) ) {
				$this->outputResourceHint( 'preload', $validated_url, 'image' );
			}
		}

		if ( ! empty( $preload_urls ) ) {
			$this->logger->debug(
				'Page-specific preload URLs added',
				array(
					'post_id'     => $post_id,
					'device_type' => $device_type,
					'url_count'   => count( $preload_urls ),
				)
			);
		}
	}

	/**
	 * Add automatic resource hints based on page content.
	 */
	private function addAutomaticResourceHints(): void {
		// Preconnect to external domains used by enqueued scripts/styles
		$external_domains = $this->getExternalDomains();
		foreach ( $external_domains as $domain ) {
			$this->outputResourceHint( 'preconnect', '//' . $domain );
		}

		// Prefetch next page in pagination
		if ( is_paged() || is_singular() ) {
			$next_url = $this->getNextPageUrl();
			if ( $next_url ) {
				$this->outputResourceHint( 'prefetch', $next_url );
			}
		}
	}

	/**
	 * Output a resource hint link tag.
	 *
	 * @param string $rel  Relationship type.
	 * @param string $href URL.
	 * @param string $as   Resource type (optional).
	 */
	private function outputResourceHint( string $rel, string $href, string $as = '' ): void {
		$attributes = array(
			'rel'  => $rel,
			'href' => $href,
		);

		if ( ! empty( $as ) ) {
			$attributes['as'] = $as;
		}

		if ( in_array( $rel, array( 'preconnect', 'preload' ), true ) ) {
			$attributes['crossorigin'] = 'anonymous';
		}

		$attr_string = '';
		foreach ( $attributes as $attr => $value ) {
			$attr_string .= ' ' . esc_attr( $attr ) . '="' . esc_attr( $value ) . '"';
		}

		echo '<link' . $attr_string . '>' . "\n";
	}

	public function modify_script_loader_tag( string $tag, string $handle, string $src ): string {
		if ( is_user_logged_in() || is_admin() || empty( $src ) ) {
			return $tag;
		}

		$should_defer = $this->settingsService->get_setting( 'file_optimisation', 'deferJs' );
		$should_delay = $this->settingsService->get_setting( 'file_optimisation', 'delayJs' );

		if ( $should_delay ) {
			$tag = str_replace( ' src=', ' data-wppo-src=', $tag );
			$tag = str_replace( ' type=', ' data-wppo-type=', $tag );
		} elseif ( $should_defer ) {
			$tag = str_replace( ' src=', ' defer src=', $tag );
		}

		return $tag;
	}

	public function modify_style_loader_tag( string $tag, string $handle, string $href ): string {
		if ( is_user_logged_in() || is_admin() || empty( $href ) ) {
			return $tag;
		}

		return $tag;
	}

	public function handle_css_combination(): void {
		$this->optimizationService->combine_css();
	}

	public function conditionally_remove_woocommerce_assets(): void {
		if ( ! class_exists( 'WooCommerce' ) || is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) {
			return;
		}

		$styles_to_remove  = array( 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-general' );
		$scripts_to_remove = array( 'wc-cart-fragments', 'woocommerce', 'wc-add-to-cart' );

		$removed_styles  = 0;
		$removed_scripts = 0;

		foreach ( $styles_to_remove as $handle ) {
			if ( wp_style_is( $handle, 'enqueued' ) ) {
				wp_dequeue_style( $handle );
				++$removed_styles;
			}
		}

		foreach ( $scripts_to_remove as $handle ) {
			if ( wp_script_is( $handle, 'enqueued' ) ) {
				wp_dequeue_script( $handle );
				++$removed_scripts;
			}
		}

		if ( $removed_styles > 0 || $removed_scripts > 0 ) {
			$this->logger->debug(
				'WooCommerce assets removed from non-shop pages',
				array(
					'removed_styles'  => $removed_styles,
					'removed_scripts' => $removed_scripts,
					'page_url'        => $_SERVER['REQUEST_URI'] ?? '',
				)
			);
		}
	}

	/**
	 * Add critical CSS to page head.
	 */
	public function add_critical_css(): void {
		if ( is_admin() ) {
			return;
		}

		$critical_css = $this->getCriticalCSS();
		if ( ! empty( $critical_css ) ) {
			echo '<style id="wppo-critical-css">' . $critical_css . '</style>' . "\n";
		}
	}

	/**
	 * Add performance hints to page head.
	 */
	public function add_performance_hints(): void {
		if ( is_admin() ) {
			return;
		}

		// Add viewport meta tag if not present
		if ( ! $this->hasViewportMeta() ) {
			echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		}

		// Add performance timing API initialization
		if ( $this->settingsService->get_setting( 'performance', 'enable_timing_api' ) ) {
			echo '<script>window.wppoTiming = {start: performance.now()};</script>' . "\n";
		}
	}

	/**
	 * Add lazy loading attributes to images.
	 *
	 * @param array  $attr       Image attributes.
	 * @param object $attachment Attachment object.
	 * @param string $size      Image size.
	 * @return array Modified attributes.
	 */
	public function add_lazy_loading_attributes( array $attr, $attachment, string $size ): array {
		if ( is_admin() || ! $this->settingsService->get_setting( 'image_optimisation', 'lazy_loading' ) ) {
			return $attr;
		}

		// Skip if already has loading attribute
		if ( isset( $attr['loading'] ) ) {
			return $attr;
		}

		// Add native lazy loading
		$attr['loading']  = 'lazy';
		$attr['decoding'] = 'async';

		return $attr;
	}

	/**
	 * Optimize images in content.
	 *
	 * @param string $content Post content.
	 * @return string Optimized content.
	 */
	public function optimize_content_images( string $content ): string {
		if ( is_admin() || ! $this->settingsService->get_setting( 'image_optimisation', 'optimize_content_images' ) ) {
			return $content;
		}

		// Add lazy loading to content images
		$content = preg_replace_callback(
			'/<img([^>]+)>/i',
			array( $this, 'optimize_image_tag' ),
			$content
		);

		return $content;
	}

	/**
	 * Optimize individual image tag.
	 *
	 * @param array $matches Regex matches.
	 * @return string Optimized image tag.
	 */
	private function optimize_image_tag( array $matches ): string {
		$img_tag    = $matches[0];
		$attributes = $matches[1];

		// Skip if already optimized
		if ( strpos( $attributes, 'loading=' ) !== false ) {
			return $img_tag;
		}

		// Add lazy loading attributes
		$optimized_attributes = $attributes . ' loading="lazy" decoding="async"';

		return '<img' . $optimized_attributes . '>';
	}

	/**
	 * Handle JavaScript combination.
	 */
	public function handle_js_combination(): void {
		$this->optimizationService->combine_js();
	}

	/**
	 * Start HTML optimization buffer.
	 */
	public function start_html_optimization(): void {
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}

		ob_start( array( $this, 'optimize_html_output' ) );
	}

	/**
	 * Optimize HTML output.
	 *
	 * @param string $html HTML content.
	 * @return string Optimized HTML.
	 */
	public function optimize_html_output( string $html ): string {
		if ( empty( $html ) ) {
			return $html;
		}

		try {
			$html_optimizer = $this->container->get( 'html_optimizer' );
			return $html_optimizer->optimize( $html );
		} catch ( \Exception $e ) {
			$this->logger->error( 'HTML optimization failed: ' . $e->getMessage() );
			return $html;
		}
	}

	/**
	 * Add performance monitoring to footer.
	 */
	public function add_performance_monitoring(): void {
		if ( is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->settingsService->get_setting( 'performance', 'enable_frontend_monitoring' ) ) {
			return;
		}

		$performance_data = array(
			'pageLoadTime'         => 'performance.timing.loadEventEnd - performance.timing.navigationStart',
			'domContentLoaded'     => 'performance.timing.domContentLoadedEventEnd - performance.timing.navigationStart',
			'firstPaint'           => 'performance.getEntriesByType("paint")[0]?.startTime',
			'firstContentfulPaint' => 'performance.getEntriesByType("paint")[1]?.startTime',
		);

		echo '<script>';
		echo 'window.addEventListener("load", function() {';
		echo 'setTimeout(function() {';
		echo 'var perfData = {';
		foreach ( $performance_data as $key => $value ) {
			echo $key . ': ' . $value . ',';
		}
		echo 'url: window.location.href';
		echo '};';
		echo 'console.log("WPPO Performance:", perfData);';
		echo '}, 1000);';
		echo '});';
		echo '</script>';
	}

	/**
	 * Get placeholder image source.
	 *
	 * @return string Placeholder image data URI.
	 */
	private function getPlaceholderImageSrc(): string {
		return 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"%3E%3C/svg%3E';
	}

	/**
	 * Get current page type.
	 *
	 * @return string Page type.
	 */
	private function getCurrentPageType(): string {
		if ( is_front_page() ) {
			return 'front_page';
		} elseif ( is_home() ) {
			return 'blog_home';
		} elseif ( is_single() ) {
			return 'single_post';
		} elseif ( is_page() ) {
			return 'page';
		} elseif ( is_category() ) {
			return 'category';
		} elseif ( is_tag() ) {
			return 'tag';
		} elseif ( is_archive() ) {
			return 'archive';
		} elseif ( is_search() ) {
			return 'search';
		} else {
			return 'other';
		}
	}

	/**
	 * Get critical resources for preloading.
	 *
	 * @return array Critical resources.
	 */
	private function getCriticalResources(): array {
		$resources = array();

		// Add critical CSS files
		$critical_css_handles = $this->settingsService->get_setting( 'preload_settings', 'critical_css_handles' ) ?? array();
		foreach ( $critical_css_handles as $handle ) {
			if ( wp_style_is( $handle, 'registered' ) ) {
				$resources[] = array(
					'url'      => wp_styles()->registered[ $handle ]->src,
					'type'     => 'style',
					'priority' => 'high',
				);
			}
		}

		// Add critical JS files
		$critical_js_handles = $this->settingsService->get_setting( 'preload_settings', 'critical_js_handles' ) ?? array();
		foreach ( $critical_js_handles as $handle ) {
			if ( wp_script_is( $handle, 'registered' ) ) {
				$resources[] = array(
					'url'      => wp_scripts()->registered[ $handle ]->src,
					'type'     => 'script',
					'priority' => 'medium',
				);
			}
		}

		return $resources;
	}

	/**
	 * Detect device type based on user agent.
	 *
	 * @return string Device type (mobile, tablet, desktop).
	 */
	private function detectDeviceType(): string {
		$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

		if ( wp_is_mobile() ) {
			// Further distinguish between mobile and tablet
			if ( preg_match( '/tablet|ipad/i', $user_agent ) ) {
				return 'tablet';
			}
			return 'mobile';
		}

		return 'desktop';
	}

	/**
	 * Get resource type from URL.
	 *
	 * @param string $url Resource URL.
	 * @return string Resource type.
	 */
	private function getResourceType( string $url ): string {
		$extension = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

		$type_map = array(
			'css'   => 'style',
			'js'    => 'script',
			'woff'  => 'font',
			'woff2' => 'font',
			'ttf'   => 'font',
			'otf'   => 'font',
			'jpg'   => 'image',
			'jpeg'  => 'image',
			'png'   => 'image',
			'gif'   => 'image',
			'webp'  => 'image',
			'avif'  => 'image',
			'svg'   => 'image',
		);

		return $type_map[ $extension ] ?? '';
	}

	/**
	 * Get external domains from enqueued assets.
	 *
	 * @return array External domains.
	 */
	private function getExternalDomains(): array {
		$domains     = array();
		$site_domain = parse_url( home_url(), PHP_URL_HOST );

		// Check enqueued styles
		foreach ( wp_styles()->queue as $handle ) {
			if ( isset( wp_styles()->registered[ $handle ] ) ) {
				$src    = wp_styles()->registered[ $handle ]->src;
				$domain = parse_url( $src, PHP_URL_HOST );
				if ( $domain && $domain !== $site_domain ) {
					$domains[] = $domain;
				}
			}
		}

		// Check enqueued scripts
		foreach ( wp_scripts()->queue as $handle ) {
			if ( isset( wp_scripts()->registered[ $handle ] ) ) {
				$src    = wp_scripts()->registered[ $handle ]->src;
				$domain = parse_url( $src, PHP_URL_HOST );
				if ( $domain && $domain !== $site_domain ) {
					$domains[] = $domain;
				}
			}
		}

		return array_unique( $domains );
	}

	/**
	 * Get next page URL for prefetching.
	 *
	 * @return string|null Next page URL.
	 */
	private function getNextPageUrl(): ?string {
		if ( is_singular() ) {
			// Get next post in same category
			$next_post = get_next_post( true );
			return $next_post ? get_permalink( $next_post ) : null;
		}

		if ( is_paged() ) {
			// Get next page in pagination
			$next_page = get_next_posts_page_link();
			return $next_page ?: null;
		}

		return null;
	}

	/**
	 * Get critical CSS for current page.
	 *
	 * @return string Critical CSS.
	 */
	private function getCriticalCSS(): string {
		$page_type            = $this->getCurrentPageType();
		$critical_css_setting = $this->settingsService->get_setting( 'critical_css', $page_type );

		if ( ! empty( $critical_css_setting ) ) {
			return $critical_css_setting;
		}

		// Generate critical CSS automatically if enabled
		if ( $this->settingsService->get_setting( 'critical_css', 'auto_generate' ) ) {
			return $this->generateCriticalCSS();
		}

		return '';
	}

	/**
	 * Generate critical CSS automatically.
	 *
	 * @return string Generated critical CSS.
	 */
	private function generateCriticalCSS(): string {
		// This is a simplified implementation
		// In a real scenario, you'd use tools like Puppeteer or similar
		$critical_selectors = array(
			'body',
			'html',
			'header',
			'nav',
			'.site-header',
			'h1',
			'h2',
			'.hero',
			'.banner',
			'.above-fold',
		);

		$critical_css = '';
		foreach ( $critical_selectors as $selector ) {
			// Extract CSS rules for critical selectors
			// This would need a proper CSS parser in production
			$critical_css .= $selector . '{display:block;}';
		}

		return $critical_css;
	}

	/**
	 * Check if viewport meta tag exists.
	 *
	 * @return bool True if viewport meta exists.
	 */
	private function hasViewportMeta(): bool {
		// This is a simplified check
		// In production, you'd parse the head content
		return has_action( 'wp_head', 'wp_site_icon' ); // Proxy check
	}
}
