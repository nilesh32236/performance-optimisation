# Performance Optimisation Plugin API Documentation

## Overview

This document provides comprehensive documentation for the Performance Optimisation plugin's API, including utility classes, services, and REST endpoints.

## Utility Classes

### FileSystemUtil

Provides secure file system operations with proper validation and error handling.

#### Methods

```php
// Check if file exists
FileSystemUtil::fileExists(string $path): bool

// Check if directory exists  
FileSystemUtil::directoryExists(string $path): bool

// Create directory with proper permissions
FileSystemUtil::createDirectory(string $path, bool $recursive = false): bool

// Read file content safely
FileSystemUtil::readFile(string $path): string

// Write content to file securely
FileSystemUtil::writeFile(string $path, string $content): bool

// Get file size
FileSystemUtil::getFileSize(string $path): int

// Delete file safely
FileSystemUtil::deleteFile(string $path): bool

// Sanitize file path
FileSystemUtil::sanitizePath(string $path): string

// Convert file path to URL
FileSystemUtil::pathToUrl(string $path): string
```

#### Usage Example

```php
use PerformanceOptimisation\Utils\FileSystemUtil;

// Safe file operations
if (FileSystemUtil::fileExists($file_path)) {
    $content = FileSystemUtil::readFile($file_path);
    $processed = process_content($content);
    FileSystemUtil::writeFile($output_path, $processed);
}
```

### CacheUtil

Unified cache management with support for multiple cache types and expiration policies.

#### Methods

```php
// Generate cache key
CacheUtil::generateCacheKey(string $data, string $prefix = ''): string

// Set cache value
CacheUtil::set(string $key, mixed $value, int $expiry = 3600, string $group = 'default'): bool

// Get cache value
CacheUtil::get(string $key, string $group = 'default'): mixed

// Delete cache entry
CacheUtil::delete(string $key, string $group = 'default'): bool

// Flush cache group
CacheUtil::flushGroup(string $group): bool

// Get cache expiry for type
CacheUtil::getCacheExpiry(string $type): int
```

### ValidationUtil

Input validation and sanitization utilities for security.

#### Methods

```php
// Sanitize URL
ValidationUtil::sanitizeUrl(string $url): string

// Sanitize file path
ValidationUtil::sanitizePath(string $path): string

// Sanitize HTML content
ValidationUtil::sanitizeHtml(string $html): string

// Validate email address
ValidationUtil::validateEmail(string $email): bool

// Validate numeric value
ValidationUtil::validateNumeric(string $value): bool

// Validate value range
ValidationUtil::validateRange(float $value, float $min, float $max): bool
```

## Services

### CacheService

High-level cache management service with intelligent invalidation.

#### Methods

```php
// Clear cache by type
clearCache(string $type = 'all'): bool

// Invalidate specific URL cache
invalidateCache(string $url): bool

// Get formatted cache size
getCacheSize(): string

// Preload cache for URLs
preloadCache(array $urls): array

// Check if caching is enabled
isCacheEnabled(): bool
```

#### Usage Example

```php
use PerformanceOptimisation\Services\CacheService;

$cacheService = $container->get('cache_service');

// Clear all cache
$cacheService->clearCache('all');

// Invalidate specific page
$cacheService->invalidateCache('https://example.com/page/');

// Preload important pages
$cacheService->preloadCache([
    'https://example.com/',
    'https://example.com/about/',
    'https://example.com/contact/'
]);
```

## Optimizers

### ModernImageProcessor

Advanced image processing with modern format support.

#### Methods

```php
// Optimize image with options
optimize(string $image_path, array $options = []): array

// Get optimal format for browser
getOptimalFormat(string $user_agent): string

// Generate responsive image srcset
generateSrcset(array $responsive_images): string

// Process image for lazy loading
processForLazyLoading(string $image_path, array $options = []): array
```

#### Options

```php
$options = [
    'quality' => 85,                    // Image quality (1-100)
    'progressive' => true,              // Progressive JPEG
    'auto_format' => true,              // Auto WebP/AVIF conversion
    'generate_responsive' => true,      // Generate responsive sizes
    'strip_metadata' => true,           // Remove EXIF data
];
```

### ModernCssOptimizer

CSS optimization with critical CSS extraction and minification.

#### Methods

```php
// Optimize CSS content
optimize(string $css_content, array $options = []): array

// Optimize CSS file
optimizeFile(string $file_path, array $options = []): array

// Combine multiple CSS files
combineFiles(array $file_paths, array $options = []): array
```

## REST API Endpoints

### Cache Management

#### Clear Cache
```
POST /wp-json/performance-optimisation/v1/cache/clear
```

**Parameters:**
- `type` (string): Cache type - 'all', 'page', 'object', 'minified'
- `path` (string, optional): Specific path for page cache

**Response:**
```json
{
    "success": true,
    "message": "Cache cleared successfully",
    "type": "all",
    "cleared_items": 1,
    "timestamp": "2023-12-01 10:30:00"
}
```

#### Cache Statistics
```
GET /wp-json/performance-optimisation/v1/cache/stats
```

**Response:**
```json
{
    "success": true,
    "data": {
        "total_size": "15.2 MB",
        "page_cache_size": "8.5 MB",
        "object_cache_size": "4.2 MB",
        "minified_cache_size": "2.5 MB",
        "hit_ratio": 85.3
    }
}
```

### Image Optimization

#### Optimize Single Image
```
POST /wp-json/performance-optimisation/v1/images/optimize
```

**Parameters:**
- `image_id` (int): WordPress attachment ID
- `options` (object): Optimization options

**Response:**
```json
{
    "success": true,
    "data": {
        "original_size": 1024000,
        "optimized_size": 512000,
        "compression_ratio": 50.0,
        "modern_formats": {
            "webp": {
                "path": "/path/to/image.webp",
                "size": 384000
            }
        }
    }
}
```

#### Batch Optimize Images
```
POST /wp-json/performance-optimisation/v1/images/batch-optimize
```

**Parameters:**
- `image_ids` (array): Array of attachment IDs
- `limit` (int): Maximum images to process (1-50)
- `options` (object): Optimization options

**Response:**
```json
{
    "success": true,
    "batch_id": "batch_123456",
    "total": 25,
    "status": "processing"
}
```

## Error Handling

All API endpoints return consistent error responses:

```json
{
    "success": false,
    "error": "error_code",
    "message": "Human readable error message",
    "data": {
        "additional": "error details"
    }
}
```

## Rate Limiting

API endpoints implement rate limiting:
- Cache operations: 10 requests per 5 minutes
- Image optimization: 20 requests per 5 minutes  
- Batch operations: 5 requests per 10 minutes

## Authentication

All API endpoints require:
- Valid WordPress nonce in `X-WP-Nonce` header
- User with `manage_options` capability

## Migration Guide

### From Legacy Code

Replace legacy optimizer usage:

```php
// Old way
$old_optimizer = new LegacyOptimizer();
$result = $old_optimizer->process($content);

// New way
$container = ServiceContainer::getInstance();
$optimizer = $container->get('css_optimizer');
$result = $optimizer->optimize($content, $options);
```

### Service Container Usage

```php
// Get service container
$container = ServiceContainer::getInstance();

// Resolve services
$logger = $container->get('logger');
$cache = $container->get('cache_service');
$filesystem = $container->get('filesystem');

// Use dependency injection in constructors
class MyService {
    public function __construct(
        private LoggingUtil $logger,
        private CacheService $cache
    ) {}
}
```

## Best Practices

1. **Always use dependency injection** instead of direct instantiation
2. **Validate all inputs** using ValidationUtil methods
3. **Handle errors gracefully** with try-catch blocks
4. **Use caching** for expensive operations
5. **Log important events** for debugging and monitoring
6. **Follow WordPress coding standards** for consistency

## Performance Considerations

- Cache expensive operations using CacheUtil
- Use batch processing for multiple items
- Implement proper rate limiting for API endpoints
- Monitor memory usage for large file operations
- Use progressive processing for time-consuming tasks
