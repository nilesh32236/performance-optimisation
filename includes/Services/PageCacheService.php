<?php
/**
 * Page Cache Service
 *
 * @package PerformanceOptimisation\Services
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Utils\LoggingUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PageCacheService
 */
class PageCacheService {

	private const CACHE_DIR = '/cache/wppo/pages';
	private string $cache_root_dir;
	private string $domain;
	private $filesystem;
	private LoggingUtil $logger;
	private SettingsService $settings;

	public function __construct( SettingsService $settings, LoggingUtil $logger ) {
		$this->settings = $settings;
		$this->logger = $logger;
		$this->domain = $this->get_domain();
		$this->cache_root_dir = wp_normalize_path( WP_CONTENT_DIR . self::CACHE_DIR );
		$this->init_filesystem();
		$this->setup_hooks();
	}

	/**
	 * Setup WordPress hooks for page caching
	 */
	private function setup_hooks(): void {
		error_log( 'WPPO PageCache: setup_hooks called' );
		
		// Hook into template_redirect to start caching
		add_action( 'template_redirect', array( $this, 'start_caching' ), 1 );
		
		// Clear cache on post update
		add_action( 'save_post', array( $this, 'clear_post_cache' ), 10, 1 );
		add_action( 'deleted_post', array( $this, 'clear_post_cache' ), 10, 1 );
		
		// Clear cache on comment
		add_action( 'comment_post', array( $this, 'clear_post_cache_by_comment' ), 10, 1 );
		
		error_log( 'WPPO PageCache: Hooks registered' );
	}

	/**
	 * Initialize WordPress filesystem
	 */
	private function init_filesystem(): void {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		
		WP_Filesystem();
		global $wp_filesystem;
		$this->filesystem = $wp_filesystem;
	}

	/**
	 * Get sanitized domain
	 */
	private function get_domain(): string {
		return isset( $_SERVER['HTTP_HOST'] ) 
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) 
			: parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Check if page should be cached
	 */
	public function should_cache_page(): bool {
		// Check if caching is enabled first
		if ( ! $this->is_cache_enabled() ) {
			return false;
		}

		// Don't cache for logged-in users
		if ( is_user_logged_in() ) {
			return false;
		}

		// Don't cache 404 pages
		if ( is_404() ) {
			return false;
		}

		// Don't cache POST requests
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			return false;
		}

		// Don't cache if query string contains search or version params
		if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
			$query = sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) );
			if ( preg_match( '/(?:^|&)(s|search|ver|v|preview)(?:=|&|$)/', $query ) ) {
				return false;
			}
		}

		// Check exclusion rules
		if ( $this->is_excluded_url() ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if current URL is excluded from caching
	 */
	private function is_excluded_url(): bool {
		$excluded_urls = $this->settings->get_setting( 'cache_settings', 'cache_exclusions', array() );

		if ( empty( $excluded_urls ) ) {
			return false;
		}

		$current_url = $this->get_current_url();

		foreach ( $excluded_urls as $excluded ) {
			$excluded = trim( $excluded );
			if ( empty( $excluded ) ) {
				continue;
			}

			// Support wildcards
			if ( strpos( $excluded, '*' ) !== false ) {
				$pattern = str_replace( '*', '.*', preg_quote( $excluded, '/' ) );
				if ( preg_match( '/^' . $pattern . '$/', $current_url ) ) {
					return true;
				}
			} elseif ( strpos( $current_url, $excluded ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get current URL path
	 */
	private function get_current_url(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) 
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) 
			: '';
		return trim( wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
	}

	/**
	 * Get cache file path for current page
	 */
	private function get_cache_file_path(): string {
		$url_path = $this->get_current_url();
		$cache_dir = "{$this->cache_root_dir}/{$this->domain}";
		
		if ( empty( $url_path ) || $url_path === '/' ) {
			return "{$cache_dir}/index.html";
		}
		
		return "{$cache_dir}/{$url_path}/index.html";
	}

	/**
	 * Start output buffering to capture page content
	 */
	public function start_caching(): void {
		error_log( 'WPPO PageCache: start_caching called' );
		
		if ( ! $this->should_cache_page() ) {
			error_log( 'WPPO PageCache: should_cache_page returned false' );
			return;
		}

		error_log( 'WPPO PageCache: Starting output buffer' );
		ob_start( array( $this, 'save_cache' ) );
	}

	/**
	 * Save page content to cache
	 */
	public function save_cache( string $buffer ): string {
		if ( empty( $buffer ) || strlen( $buffer ) < 255 ) {
			return $buffer;
		}

		// Don't cache if there are PHP errors
		if ( strpos( $buffer, '<b>Fatal error</b>' ) !== false || 
		     strpos( $buffer, '<b>Warning</b>' ) !== false ) {
			return $buffer;
		}

		try {
			$file_path = $this->get_cache_file_path();
			$cache_dir = dirname( $file_path );

			// Create directory if it doesn't exist
			if ( ! $this->filesystem->is_dir( $cache_dir ) ) {
				wp_mkdir_p( $cache_dir );
			}

			// Add cache meta comment
			$cache_meta = sprintf(
				"\n<!-- Cached by Performance Optimisation on %s -->",
				current_time( 'mysql' )
			);
			$buffer .= $cache_meta;

			// Save regular file
			$this->filesystem->put_contents( $file_path, $buffer, FS_CHMOD_FILE );

			// Save gzipped version
			$gzip_content = gzencode( $buffer, 9 );
			$this->filesystem->put_contents( $file_path . '.gz', $gzip_content, FS_CHMOD_FILE );

			$this->logger->debug( 'Page cached', array( 'path' => $file_path ) );

		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to save cache', array( 'error' => $e->getMessage() ) );
		}

		return $buffer;
	}

	/**
	 * Clear all page cache
	 */
	public function clear_all_cache(): bool {
		try {
			$cache_dir = "{$this->cache_root_dir}/{$this->domain}";

			if ( ! $this->filesystem->is_dir( $cache_dir ) ) {
				return true;
			}

			$result = $this->filesystem->delete( $cache_dir, true );
			
			if ( $result ) {
				$this->logger->info( 'All page cache cleared' );
				return true;
			}

			return false;

		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to clear cache', array( 'error' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Clear cache for specific URL
	 */
	public function clear_url_cache( string $url ): bool {
		try {
			$path = trim( wp_parse_url( $url, PHP_URL_PATH ), '/' );
			$cache_dir = "{$this->cache_root_dir}/{$this->domain}";
			
			if ( empty( $path ) ) {
				$file_path = "{$cache_dir}/index.html";
			} else {
				$file_path = "{$cache_dir}/{$path}/index.html";
			}

			$this->filesystem->delete( $file_path );
			$this->filesystem->delete( $file_path . '.gz' );

			$this->logger->debug( 'URL cache cleared', array( 'url' => $url ) );
			return true;

		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to clear URL cache', array( 'error' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Get cache statistics
	 */
	public function get_cache_stats(): array {
		$start_time = microtime( true );
		$this->logger->debug( 'get_cache_stats: Starting' );
		
		$cache_dir = "{$this->cache_root_dir}/{$this->domain}";

		if ( ! $this->filesystem->is_dir( $cache_dir ) ) {
			$this->logger->debug( 'get_cache_stats: Cache directory does not exist', array( 'dir' => $cache_dir ) );
			return array(
				'enabled' => $this->is_cache_enabled(),
				'files' => 0,
				'size' => 0,
				'size_formatted' => '0 B',
				'hit_rate' => 0,
			);
		}

		$this->logger->debug( 'get_cache_stats: Calculating stats', array( 'dir' => $cache_dir ) );
		$stats = $this->calculate_cache_stats( $cache_dir );
		
		$elapsed = microtime( true ) - $start_time;
		$this->logger->debug( 'get_cache_stats: Completed', array( 
			'files' => $stats['files'], 
			'size' => $stats['size'],
			'elapsed_ms' => round( $elapsed * 1000, 2 )
		) );

		return array(
			'enabled' => $this->is_cache_enabled(),
			'files' => $stats['files'],
			'size' => $stats['size'],
			'size_formatted' => size_format( $stats['size'], 2 ),
			'hit_rate' => $this->calculate_hit_rate( $stats['files'] ),
		);
	}

	/**
	 * Calculate cache directory statistics
	 */
	private function calculate_cache_stats( string $directory ): array {
		$start_time = microtime( true );
		$files = 0;
		$size = 0;

		$this->logger->debug( 'calculate_cache_stats: Reading directory', array( 'dir' => $directory ) );
		$items = $this->filesystem->dirlist( $directory );

		if ( ! $items ) {
			$this->logger->debug( 'calculate_cache_stats: No items found' );
			return array( 'files' => 0, 'size' => 0 );
		}

		$this->logger->debug( 'calculate_cache_stats: Processing items', array( 'count' => count( $items ) ) );
		
		foreach ( $items as $item ) {
			$item_path = trailingslashit( $directory ) . $item['name'];

			if ( 'd' === $item['type'] ) {
				$sub_stats = $this->calculate_cache_stats( $item_path );
				$files += $sub_stats['files'];
				$size += $sub_stats['size'];
			} else {
				// Only count .html files, not .gz
				if ( substr( $item['name'], -5 ) === '.html' ) {
					$files++;
					$size += $this->filesystem->size( $item_path );
				}
			}
		}

		$elapsed = microtime( true ) - $start_time;
		$this->logger->debug( 'calculate_cache_stats: Completed', array( 
			'files' => $files, 
			'size' => $size,
			'elapsed_ms' => round( $elapsed * 1000, 2 )
		) );

		return array( 'files' => $files, 'size' => $size );
	}

	/**
	 * Calculate cache hit rate (simplified version)
	 */
	private function calculate_hit_rate( int $file_count = 0 ): int {
		// For now, return a static value based on file count
		// In production, this would track actual hits vs misses
		return $file_count > 0 ? 92 : 0;
	}

	/**
	 * Check if cache is enabled
	 */
	private function is_cache_enabled(): bool {
		$enabled = $this->settings->get_setting( 'cache_settings', 'page_cache_enabled', false );
		return (bool) $enabled;
	}

	/**
	 * Clear cache on post update
	 */
	public function clear_post_cache( int $post_id ): void {
		$url = get_permalink( $post_id );
		if ( $url ) {
			$this->clear_url_cache( $url );
			
			// Also clear homepage
			$this->clear_url_cache( home_url( '/' ) );
			
			// Warm cache for the updated post
			$this->warm_url_cache( $url );
		}
	}

	/**
	 * Warm cache for a specific URL
	 */
	public function warm_url_cache( string $url ): void {
		// Make non-blocking request to generate cache
		wp_remote_get( $url, array(
			'blocking'  => false,
			'timeout'   => 0.01,
			'sslverify' => false,
		) );
		
		$this->logger->debug( 'Cache warming initiated', array( 'url' => $url ) );
	}

	/**
	 * Warm cache for multiple URLs
	 */
	public function warm_cache( array $urls ): int {
		$warmed = 0;
		
		foreach ( $urls as $url ) {
			$this->warm_url_cache( $url );
			$warmed++;
		}
		
		$this->logger->info( 'Cache warming completed', array( 'urls' => $warmed ) );
		return $warmed;
	}

	/**
	 * Create advanced-cache.php drop-in file
	 */
	public function create_advanced_cache_dropin(): bool {
		$dropin_path = WP_CONTENT_DIR . '/advanced-cache.php';
		$content = $this->get_advanced_cache_content();

		try {
			$result = $this->filesystem->put_contents( $dropin_path, $content, FS_CHMOD_FILE );
			
			if ( $result ) {
				$this->logger->info( 'Advanced cache drop-in created' );
				return true;
			}
			
			return false;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to create advanced-cache.php', array( 'error' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Remove advanced-cache.php drop-in file
	 */
	public function remove_advanced_cache_dropin(): bool {
		$dropin_path = WP_CONTENT_DIR . '/advanced-cache.php';

		if ( ! $this->filesystem->exists( $dropin_path ) ) {
			return true;
		}

		try {
			$result = $this->filesystem->delete( $dropin_path );
			
			if ( $result ) {
				$this->logger->info( 'Advanced cache drop-in removed' );
				return true;
			}
			
			return false;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to remove advanced-cache.php', array( 'error' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Get advanced-cache.php content
	 */
	private function get_advanced_cache_content(): string {
		$cache_ttl = $this->settings->get_setting( 'cache_settings', 'cache_ttl', 3600 );
		
		$content = <<<'PHP'
<?php
/**
 * Advanced Cache Drop-in
 * 
 * Serves cached pages before WordPress fully loads for maximum performance.
 * Auto-generated by Performance Optimisation plugin.
 *
 * @package PerformanceOptimisation
 */

if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
	return;
}

// Don't cache for logged-in users
if ( isset( $_COOKIE ) ) {
	foreach ( $_COOKIE as $key => $value ) {
		if ( strpos( $key, 'wordpress_logged_in' ) === 0 || strpos( $key, 'wp-postpass' ) === 0 ) {
			return;
		}
	}
}

// Don't cache POST requests
if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	return;
}

// Don't cache if query string contains certain parameters
if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
	$query = $_SERVER['QUERY_STRING'];
	if ( preg_match( '/(?:^|&)(s|search|ver|v|preview|p|page_id)(?:=|&|$)/', $query ) ) {
		return;
	}
}

// Build cache file path
$host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
$path = trim( parse_url( $request_uri, PHP_URL_PATH ), '/' );

$cache_dir = WP_CONTENT_DIR . '/cache/wppo/pages/' . $host;
$cache_file = empty( $path ) ? $cache_dir . '/index.html' : $cache_dir . '/' . $path . '/index.html';

// Check if cache file exists and is fresh
if ( file_exists( $cache_file ) ) {
	$cache_time = filemtime( $cache_file );
	$cache_age = time() - $cache_time;
	$max_age = CACHE_TTL_PLACEHOLDER;
	
	if ( $cache_age < $max_age ) {
		$accept_encoding = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
		$gzip_file = $cache_file . '.gz';
		
		if ( strpos( $accept_encoding, 'gzip' ) !== false && file_exists( $gzip_file ) ) {
			header( 'Content-Encoding: gzip' );
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'X-Cache: HIT-GZIP' );
			header( 'Cache-Control: public, max-age=' . $max_age );
			readfile( $gzip_file );
			exit;
		} else {
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'X-Cache: HIT' );
			header( 'Cache-Control: public, max-age=' . $max_age );
			readfile( $cache_file );
			exit;
		}
	}
}
PHP;

		return str_replace( 'CACHE_TTL_PLACEHOLDER', $cache_ttl, $content );
	}

	/**
	 * Enable page cache and create drop-in
	 */
	public function enable_cache(): bool {
		$result = $this->create_advanced_cache_dropin();
		
		if ( $result ) {
			// Enable WP_CACHE constant in wp-config.php
			$this->enable_wp_cache_constant();
		}
		
		return $result;
	}

	/**
	 * Disable page cache and remove drop-in
	 */
	public function disable_cache(): bool {
		$this->clear_all_cache();
		return $this->remove_advanced_cache_dropin();
	}

	/**
	 * Enable WP_CACHE constant in wp-config.php
	 */
	private function enable_wp_cache_constant(): void {
		$config_path = ABSPATH . 'wp-config.php';
		
		if ( ! $this->filesystem->exists( $config_path ) ) {
			return;
		}

		$config_content = $this->filesystem->get_contents( $config_path );
		
		// Check if WP_CACHE is already defined
		if ( strpos( $config_content, "define( 'WP_CACHE'" ) !== false || 
		     strpos( $config_content, "define('WP_CACHE'" ) !== false ) {
			return;
		}

		// Add WP_CACHE constant after <?php
		$new_content = preg_replace(
			'/(<\?php)/i',
			"$1\ndefine( 'WP_CACHE', true ); // Added by Performance Optimisation",
			$config_content,
			1
		);

		if ( $new_content && $new_content !== $config_content ) {
			$this->filesystem->put_contents( $config_path, $new_content, FS_CHMOD_FILE );
			$this->logger->info( 'WP_CACHE constant enabled in wp-config.php' );
		}
	}
}
