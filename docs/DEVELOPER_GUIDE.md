# Developer Guide - Performance Optimisation Plugin

Welcome to the developer guide for the Performance Optimisation plugin! This document covers the plugin's architecture, how to extend it, and best practices for development.

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Service Container](#service-container)
3. [Hooks & Filters Reference](#hooks--filters-reference)
4. [Creating Custom Services](#creating-custom-services)
5. [Extending Functionality](#extending-functionality)
6. [Code Examples](#code-examples)
7. [Best Practices](#best-practices)

---

## Architecture Overview

### Modern PHP Architecture

The plugin follows modern PHP development practices:

- **PHP 7.4+** with typed properties and return types
- **Dependency Injection** via service container
- **PSR-4 Autoloading** with Composer
- **Interface-based design** for flexibility
- **Separation of concerns** with clear responsibility boundaries

### Directory Structure

```
performance-optimisation/
├── admin/                      # React-based admin interface
│   ├── src/                   # TypeScript source files
│   └── build/                 # Compiled assets
├── includes/                   # PHP backend code
│   ├── Core/                  # Core functionality
│   │   ├── Bootstrap/         # Plugin initialization
│   │   ├── API/              # REST API controllers
│   │   └── Analytics/        # Performance analytics
│   ├── Services/             # Business logic services
│   ├── Optimizers/           # File optimization classes
│   ├── Utils/                # Utility classes
│   ├── Providers/            # Service providers
│   ├── Interfaces/           # PHP interfaces
│   ├── Exceptions/           # Custom exceptions
│   └── Admin/                # Admin-specific classes
├── docs/                      # Documentation
└── vendor/                    # Composer dependencies
```

### Request Lifecycle

```
1. WordPress loads → Plugin activation hook
2. plugins_loaded → Plugin::getInstance()->initialize()
3. Service container registers all services
4. Service providers bind implementations
5. Features initialize via hooks
6. REST API routes registered
7. Frontend/Admin hooks execute
```

---

## Service Container

### Overview

The plugin uses a custom dependency injection container for managing services and their dependencies.

### Getting the Container

```php
use PerformanceOptimisation\Core\ServiceContainer;

// Get singleton instance
$container = ServiceContainer::getInstance();
```

### Registering Services

Services are registered in [`Plugin.php`](../includes/Core/Bootstrap/Plugin.php):

```php
private function registerPluginServices(): void {
    // Register service as singleton
    $this->container->singleton(
        'cache_service',
        function($c) {
            return new \PerformanceOptimisation\Services\CacheService(
                $c->get('page_cache_service')
            );
        }
    );
    
    // Register service as instance
    $this->container->bind(
        'image_service',
        function($c) {
            return new \PerformanceOptimisation\Services\ImageService(
                $c->get('image_processor'),
                $c->get('conversion_queue'),
                $c->get('settings_service')->get_setting('images')
            );
        }
    );
}
```

### Resolving Services

```php
// Method 1: Direct get
$cache = $container->get('cache_service');

// Method 2: With type hint (if supported)
$logger = $container->get('logger');

// Method 3: Check if service exists
if ($container->has('cdn_service')) {
    $cdn = $container->get('cdn_service');
}
```

### Available Services

| Service Key | Class | Description |
|-------------|-------|-------------|
| `cache_service` | `CacheService` | Cache management |
| `image_service` | `ImageService` | Image optimization |
| `settings_service` | `SettingsService` | Plugin settings |
| `page_cache_service` | `PageCacheService` | Page-level caching |
| `browser_cache_service` | `BrowserCacheService` | Browser caching headers |
| `lazy_load_service` | `LazyLoadService` | Lazy loading functionality |
| `heartbeat_service` | `HeartbeatService` | WordPress heartbeat control |
| `cron_service` | `CronService` | Background tasks |
| `logger` | `LoggingUtil` | Logging utility |
| `validator` | `ValidationUtil` | Input validation |
| `filesystem` | `FileSystemUtil` | File operations |

---

## Hooks & Filters Reference

### Action Hooks

#### Plugin Lifecycle

```php
/**
 * Fires after plugin initialization completes
 * 
 * @since 2.0.0
 */
do_action('wppo_after_init');

/**
 * Fires before cache is cleared
 * 
 * @since 2.0.0
 * @param string $type Cache type being cleared
 */
do_action('wppo_before_cache_clear', $type);

/**
 * Fires after cache is cleared
 * 
 * @since 2.0.0
 * @param string $type Cache type that was cleared
 * @param bool $success Whether clearing was successful
 */
do_action('wppo_after_cache_clear', $type, $success);
```

#### Image Optimization

```php
/**
 * Fires before image optimization
 * 
 * @since 2.0.0
 * @param int $attachment_id Image attachment ID
 * @param array $options Optimization options
 */
do_action('wppo_before_image_optimize', $attachment_id, $options);

/**
 * Fires after image optimization
 * 
 * @since 2.0.0
 * @param int $attachment_id Image attachment ID
 * @param array $result Optimization results
 */
do_action('wppo_after_image_optimize', $attachment_id, $result);
```

### Filter Hooks

#### Settings

```php
/**
 * Filter plugin default settings
 * 
 * @since 2.0.0
 * @param array $defaults Default settings array
 * @return array Modified defaults
 */
$defaults = apply_filters('wppo_default_settings', $defaults);

/**
 * Filter settings before saving
 * 
 * @since 2.0.0
 * @param array $settings Settings to be saved
 * @return array Modified settings
 */
$settings = apply_filters('wppo_before_save_settings', $settings);
```

#### Cache

```php
/**
 * Filter cache expiration time
 * 
 * @since 2.0.0
 * @param int $expiry Expiration time in seconds
 * @param string $type Cache type
 * @return int Modified expiration time
 */
$expiry = apply_filters('wppo_cache_expiry', 3600, 'page');

/**
 * Filter URLs to exclude from caching
 * 
 * @since 2.0.0
 * @param array $exclusions Array of URL patterns to exclude
 * @return array Modified exclusions
 */
$exclusions = apply_filters('wppo_cache_exclusions', $exclusions);
```

#### Optimization

```php
/**
 * Filter CSS before minification
 * 
 * @since 2.0.0
 * @param string $css CSS content
 * @param string $file_path Original file path
 * @return string Modified CSS
 */
$css = apply_filters('wppo_before_minify_css', $css, $file_path);

/**
 * Filter JavaScript before minification
 * 
 * @since 2.0.0
 * @param string $js JavaScript content
 * @param string $file_path Original file path
 * @return string Modified JavaScript
 */
$js = apply_filters('wppo_before_minify_js', $js, $file_path);

/**
 * Filter image optimization quality
 * 
 * @since 2.0.0
 * @param int $quality Quality (1-100)
 * @param string $format Image format (jpg, png, webp, avif)
 * @return int Modified quality
 */
$quality = apply_filters('wppo_image_quality', 85, 'webp');
```

---

## Creating Custom Services

### Step 1: Create Service Class

```php
<?php
namespace YourPlugin\Services;

use PerformanceOptimisation\Services\CacheService;
use PerformanceOptimisation\Utils\LoggingUtil;

class CustomOptimizationService {
    private CacheService $cache;
    private LoggingUtil $logger;
    
    public function __construct(
        CacheService $cache,
        LoggingUtil $logger
    ) {
        $this->cache = $cache;
        $this->logger = $logger;
    }
    
    public function optimize(string $content): string {
        // Your optimization logic
        $optimized = $this->processContent($content);
        
        // Log the operation
        $this->logger->info('Content optimized', [
            'original_size' => strlen($content),
            'optimized_size' => strlen($optimized)
        ]);
        
        return $optimized;
    }
    
    private function processContent(string $content): string {
        // Implementation
        return $content;
    }
}
```

### Step 2: Register Service

```php
add_action('wppo_after_init', function() {
    $container = \PerformanceOptimisation\Core\ServiceContainer::getInstance();
    
    $container->singleton('custom_optimization', function($c) {
        return new \YourPlugin\Services\CustomOptimizationService(
            $c->get('cache_service'),
            $c->get('logger')
        );
    });
});
```

### Step 3: Use Service

```php
$container = \PerformanceOptimisation\Core\ServiceContainer::getInstance();
$optimizer = $container->get('custom_optimization');

$result = $optimizer->optimize($content);
```

---

## Extending Functionality

### Adding Custom Cache Exclusions

```php
add_filter('wppo_cache_exclusions', function($exclusions) {
    // Add custom pages to exclude from caching
    $exclusions[] = '/my-custom-page/';
    $exclusions[] = '/api/';
    
    return $exclusions;
});
```

### Custom Image Optimization

```php
add_filter('wppo_before_image_optimize', function($attachment_id, $options) {
    // Modify optimization options
    if (get_post_meta($attachment_id, 'is_logo', true)) {
        $options['quality'] = 100; // Don't compress logos
    }
    
    return $options;
}, 10, 2);
```

### Adding Custom REST API Endpoint

```php
add_action('rest_api_init', function() {
    register_rest_route('wppo/v1', '/custom-endpoint', [
        'methods' => 'GET',
        'callback' => function($request) {
            $container = \PerformanceOptimisation\Core\ServiceContainer::getInstance();
            $cache = $container->get('cache_service');
            
            return [
                'success' => true,
                'cache_stats' => $cache->getCacheSize()
            ];
        },
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
});
```

### Extending Optimization Process

```php
class CustomCSSOptimizer extends \PerformanceOptimisation\Optimizers\ModernCssOptimizer {
    
    public function optimize(string $css_content, array $options = []): array {
        // Call parent optimization
        $result = parent::optimize($css_content, $options);
        
        // Add custom optimization
        $result['content'] = $this->removeComments($result['content']);
        
        return $result;
    }
    
    private function removeComments(string $css): string {
        return preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
    }
}

// Register custom optimizer
add_filter('wppo_css_optimizer_class', function() {
    return \YourPlugin\Optimizers\CustomCSSOptimizer::class;
});
```

---

## Code Examples

### Example 1: Custom Performance Monitor

```php
<?php
class CustomPerformanceMonitor {
    private $start_time;
    private $logger;
    
    public function __construct() {
        $container = \PerformanceOptimisation\Core\ServiceContainer::getInstance();
        $this->logger = $container->get('logger');
        
        add_action('init', [$this, 'start'], 1);
        add_action('shutdown', [$this, 'end']);
    }
    
    public function start(): void {
        $this->start_time = microtime(true);
    }
    
    public function end(): void {
        $execution_time = microtime(true) - $this->start_time;
        
        if ($execution_time > 2.0) {
            $this->logger->warning('Slow page load detected', [
                'execution_time' => $execution_time,
                'url' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'memory_peak' => memory_get_peak_usage(true)
            ]);
        }
    }
}

new CustomPerformanceMonitor();
```

### Example 2: Conditional Optimization

```php
add_filter('wppo_minify_enabled', function($enabled, $type) {
    // Disable minification in development
    if (defined('WP_DEBUG') && WP_DEBUG) {
        return false;
    }
    
    // Disable JS minification for logged-in users
    if ($type === 'js' && is_user_logged_in()) {
        return false;
    }
    
    return $enabled;
}, 10, 2);
```

### Example 3: Custom Cache Warming

```php
add_action('wppo_after_cache_clear', function($type, $success) {
    if ($success && $type === 'page') {
        $container = \PerformanceOptimisation\Core\ServiceContainer::getInstance();
        $cache = $container->get('cache_service');
        
        // Warm cache for important pages
        $urls = [
            home_url('/'),
            home_url('/about/'),
            home_url('/contact/')
        ];
        
        $cache->preloadCache($urls);
    }
}, 10, 2);
```

---

## Best Practices

### 1. Use Dependency Injection

**✅ Good:**
```php
class MyService {
    private LoggingUtil $logger;
    
    public function __construct(LoggingUtil $logger) {
        $this->logger = $logger;
    }
}
```

**❌ Bad:**
```php
class MyService {
    public function doSomething() {
        $logger = new LoggingUtil(); // Direct instantiation
    }
}
```

### 2. Type Hints

**✅ Good:**
```php
public function processImage(int $attachment_id, array $options): array {
    // Implementation
}
```

**❌ Bad:**
```php
public function processImage($attachment_id, $options) {
    // No type safety
}
```

### 3. Error Handling

**✅ Good:**
```php
try {
    $result = $this->cache->get($key);
} catch (\Exception $e) {
    $this->logger->error('Cache retrieval failed', [
        'key' => $key,
        'error' => $e->getMessage()
    ]);
    return null;
}
```

**❌ Bad:**
```php
$result = $this->cache->get($key); // No error handling
```

### 4. Use WordPress Functions

**✅ Good:**
```php
$url = esc_url($input_url);
$text = sanitize_text_field($input_text);
```

**❌ Bad:**
```php
$url = $input_url; // No sanitization
```

### 5. Hook Priority

```php
// Early hooks (1-5): Core functionality
add_action('init', 'core_function', 1);

// Normal hooks (10): Standard operations
add_action('init', 'standard_function', 10);

// Late hooks (20+): Modifications and overrides
add_action('init', 'override_function', 20);
```

### 6. Performance Considerations

- Cache expensive operations
- Use transients for temporary data
- Avoid n+1 queries
- Minimize database calls
- Use object caching when available

### 7. Security

- Always validate and sanitize input
- Use nonces for forms and AJAX
- Check user capabilities
- Escape output
- Use prepared statements for queries

---

## Debugging

### Enable Debug Mode

```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WPPO_DEBUG', true);
```

### Access Debug Logs

```php
$container = \PerformanceOptimisation\Core\ServiceContainer::getInstance();
$logger = $container->get('logger');

// Log levels
$logger->debug('Debug message', ['data' => $debug_data]);
$logger->info('Information message');
$logger->warning('Warning message');
$logger->error('Error message', ['exception' => $e]);
```

### Check Service Container

```php
// List all registered services
$container = \PerformanceOptimisation\Core\ServiceContainer::getInstance();
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('Registered services: ' . print_r($container->getServices(), true));
}
```

---

## Testing

### Unit Testing Setup

```bash
# Install PHPUnit
composer require --dev phpunit/phpunit

# Run tests
vendor/bin/phpunit
```

### Example Test

```php
<?php
use PHPUnit\Framework\TestCase;

class CacheServiceTest extends TestCase {
    private $cache_service;
    
    protected function setUp(): void {
        $this->cache_service = new \PerformanceOptimisation\Services\CacheService();
    }
    
    public function testCacheClear(): void {
        $result = $this->cache_service->clearCache('all');
        $this->assertTrue($result);
    }
}
```

---

## Additional Resources

- [API Reference](API_REFERENCE.md) - Complete API documentation
- [User Guide](USER_GUIDE.md) - End-user documentation
- [Contributing Guidelines](CONTRIBUTING.md) - How to contribute
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)

---

**Questions?** Open an issue on GitHub or ask in the WordPress.org support forum.
