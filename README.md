# Performance Optimisation

A comprehensive WordPress performance optimization plugin that provides advanced caching, image optimization, file minification, and real-time performance monitoring.

![Version](https://img.shields.io/badge/version-2.0.0-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)
![License](https://img.shields.io/badge/license-GPL--2.0%2B-green.svg)

## Features

### Core Performance
- **Advanced Page Caching** - Full-page HTML caching with GZIP compression
- **Object Caching** - Database query and PHP object caching
- **Browser Caching** - Configurable cache headers for static resources
- **Cache Preloading** - Automatic cache generation for important pages

### Image Optimization
- **Modern Format Conversion** - WebP and AVIF support
- **Lazy Loading** - Load images only when needed
- **Smart Compression** - Adjustable quality settings (50-100%)
- **Automatic Resizing** - Resize large images to specified dimensions
- **Bulk Optimization** - Process existing images in batches

### File Optimization
- **CSS/JS Minification** - Remove whitespace and compress files
- **HTML Minification** - Clean up HTML output
- **File Combining** - Merge multiple CSS/JS files
- **Critical CSS** - Inline above-the-fold CSS
- **Resource Hints** - DNS prefetch, preconnect, and preload

### Performance Analytics
- **Real-time Monitoring** - Live performance metrics
- **Performance Scoring** - Overall site performance rating
- **Cache Statistics** - Hit ratios, sizes, and efficiency metrics
- **Load Time Tracking** - Average page load times
- **Optimization Reports** - Detailed performance insights

### Advanced Features
- **WordPress Optimizations** - Disable emojis, embeds, XML-RPC
- **Database Cleanup** - Remove revisions, spam, and optimize tables
- **Security Enhancements** - Hide WP version, disable file editing
- **Setup Wizard** - 5-step guided configuration

## Requirements

- **WordPress**: 6.2 or higher
- **PHP**: 7.4 or higher
- **Memory**: 128MB minimum (256MB recommended)
- **Disk Space**: 50MB for plugin files and cache storage
- **Permissions**: Write access to wp-content directory

## Installation

### Automatic Installation
1. Go to **Plugins > Add New** in your WordPress admin
2. Search for "Performance Optimisation"
3. Click **Install Now** and then **Activate**

### Manual Installation
1. Download the plugin ZIP file
2. Go to **Plugins > Add New > Upload Plugin**
3. Choose the ZIP file and click **Install Now**
4. Activate the plugin

### FTP Installation
1. Extract the plugin ZIP file
2. Upload the `performance-optimisation` folder to `/wp-content/plugins/`
3. Activate the plugin through the WordPress admin

## Quick Start

After activation, you'll be redirected to the **Setup Wizard**. Follow the 5-step process:

1. **Welcome** - Introduction and requirements check
2. **Site Detection** - Automatic site analysis
3. **Preset Selection** - Choose optimization level
4. **Feature Selection** - Customize specific features
5. **Completion** - Review and apply settings

## Configuration

Navigate to **Performance Optimisation** in your WordPress admin to access:

- **Dashboard** - Overview and quick actions
- **Caching** - Page, object, and browser caching settings
- **Optimization** - File minification and combining options
- **Images** - Lazy loading and format conversion settings
- **Advanced** - WordPress and database optimizations

## API Endpoints

The plugin provides REST API endpoints for integration:

```
GET  /wp-json/performance-optimisation/v1/analytics/dashboard
GET  /wp-json/performance-optimisation/v1/analytics/metrics
POST /wp-json/performance-optimisation/v1/cache/clear
POST /wp-json/performance-optimisation/v1/wizard/setup
GET  /wp-json/performance-optimisation/v1/recommendations
```

## Hooks and Filters

### Filters
```php
// Modify cache exclusions
add_filter('wppo_cache_exclusions', function($exclusions) {
    $exclusions[] = '/custom-page/';
    return $exclusions;
});

// Custom optimization settings
add_filter('wppo_optimization_settings', function($settings) {
    $settings['custom_feature'] = true;
    return $settings;
});
```

### Actions
```php
// Before cache clear
add_action('wppo_before_cache_clear', function() {
    // Custom logic before cache clear
});
```

## Troubleshooting

### Common Issues

**Cache Not Working**
- Check file permissions on wp-content directory
- Verify cache directory is writable
- Check for conflicting caching plugins

**Images Not Optimizing**
- Ensure GD or ImageMagick is installed
- Check PHP memory limit (256MB recommended)
- Verify write permissions on uploads directory

**JavaScript/CSS Issues**
- Test with minification disabled
- Check for JavaScript errors in console
- Clear browser cache

### Debug Mode
```php
// Enable debug logging
define('WPPO_DEBUG', true);
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Run tests: `composer test`
5. Submit a pull request

## License

This plugin is licensed under the GPL v2 or later.

## Support

- [Documentation](https://wordpress.org/plugins/performance-optimisation/)
- [Support Forum](https://wordpress.org/support/plugin/performance-optimisation/)
- [GitHub Issues](https://github.com/example-repo/performance-optimisation/issues)

## Author

**Nilesh Kanzariya**
- WordPress Profile: [nileshkanzariya](https://profiles.wordpress.org/nileshkanzariya/)
- Email: nilesh.kanzariya912@gmail.com