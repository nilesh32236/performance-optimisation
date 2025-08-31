# Performance Optimisation Plugin - User Guide

## Table of Contents

1. [Getting Started](#getting-started)
2. [Setup Wizard](#setup-wizard)
3. [Dashboard Overview](#dashboard-overview)
4. [Feature Configuration](#feature-configuration)
5. [Analytics & Monitoring](#analytics--monitoring)
6. [Troubleshooting](#troubleshooting)
7. [FAQ](#faq)
8. [Advanced Configuration](#advanced-configuration)

## Getting Started

### Installation

1. **From WordPress Admin:**
   - Go to Plugins → Add New
   - Search for "Performance Optimisation"
   - Click "Install Now" and then "Activate"

2. **Manual Installation:**
   - Download the plugin zip file
   - Go to Plugins → Add New → Upload Plugin
   - Choose the zip file and click "Install Now"
   - Activate the plugin

### First Time Setup

After activation, you'll be redirected to the Setup Wizard. This wizard will:
- Analyze your site's current performance
- Recommend optimal settings based on your hosting environment
- Configure basic optimization features
- Set up monitoring and analytics

## Setup Wizard

### Step 1: Site Analysis
The wizard automatically detects:
- **Hosting Environment:** Shared, VPS, or dedicated server
- **Current Performance:** Page load times and bottlenecks
- **Installed Plugins:** Potential conflicts or complementary plugins
- **Theme Compatibility:** Optimization compatibility with your theme

### Step 2: Optimization Level
Choose your optimization level:

#### Safe Mode (Recommended for Beginners)
- ✅ Page caching
- ✅ Image lazy loading
- ✅ Basic CSS minification
- ❌ JavaScript optimization (to avoid conflicts)

#### Recommended Mode (Most Popular)
- ✅ All Safe Mode features
- ✅ JavaScript minification
- ✅ HTML compression
- ✅ Image format conversion (WebP)
- ✅ Cache preloading

#### Advanced Mode (For Experienced Users)
- ✅ All Recommended Mode features
- ✅ Critical CSS generation
- ✅ JavaScript deferring
- ✅ Advanced image optimization (AVIF)
- ✅ Database optimization

### Step 3: Additional Features
Configure optional features:
- **Image Optimization:** Convert images to modern formats
- **Cache Preloading:** Automatically warm up cache
- **Analytics:** Enable performance monitoring
- **Automated Optimization:** Let the plugin optimize automatically

### Step 4: Completion
Review your settings and complete the setup. The wizard will:
- Apply your chosen configuration
- Run initial optimizations
- Set up monitoring
- Provide next steps

## Dashboard Overview

### Performance Metrics
The main dashboard shows:

#### Performance Score (0-100)
- **90-100:** Excellent performance
- **70-89:** Good performance
- **50-69:** Needs improvement
- **Below 50:** Poor performance, optimization required

#### Key Metrics
- **Page Load Time:** Average time to fully load pages
- **First Contentful Paint:** Time to first visible content
- **Largest Contentful Paint:** Time to main content
- **Cache Hit Ratio:** Percentage of requests served from cache

#### Optimization Status
Visual indicators showing:
- ✅ Active optimizations
- ⚠️ Partially configured features
- ❌ Disabled or problematic features

### Quick Actions
- **Clear All Caches:** Instantly clear all cached content
- **Run Performance Test:** Analyze current performance
- **Optimize Images:** Process pending image optimizations
- **Generate Report:** Create detailed performance report

## Feature Configuration

### Caching

#### Page Caching
**What it does:** Stores complete HTML pages to serve them faster
**Recommended:** Always enabled for most sites

**Settings:**
- **Cache Expiration:** How long to keep cached pages (default: 1 hour)
- **Cache Exclusions:** Pages/URLs to never cache
- **Mobile Caching:** Separate cache for mobile devices
- **User-specific Caching:** Cache for logged-in users

**Best Practices:**
- Exclude checkout, cart, and user account pages
- Use shorter expiration for frequently updated content
- Enable mobile caching if you have responsive design

#### Object Caching
**What it does:** Caches database queries and PHP objects
**Recommended:** Enable if your host supports Redis or Memcached

**Configuration:**
- Automatically detects available object cache systems
- No manual configuration required
- Shows cache hit rates in analytics

### File Optimization

#### CSS Optimization
- **Minification:** Remove unnecessary characters (spaces, comments)
- **Combination:** Merge multiple CSS files into one
- **Critical CSS:** Inline above-the-fold CSS for faster rendering

**Settings:**
- ✅ **Minify CSS:** Safe for all sites
- ⚠️ **Combine CSS:** May cause styling issues with some themes
- ⚠️ **Critical CSS:** Advanced feature, test thoroughly

#### JavaScript Optimization
- **Minification:** Compress JavaScript files
- **Deferring:** Load JavaScript after page content
- **Async Loading:** Load JavaScript without blocking page rendering

**Settings:**
- ✅ **Minify JavaScript:** Generally safe
- ⚠️ **Defer JavaScript:** May break some functionality
- ⚠️ **Async Loading:** Advanced users only

#### HTML Optimization
- **Minification:** Remove unnecessary whitespace and comments
- **Compression:** Gzip compression for smaller file sizes

### Image Optimization

#### Format Conversion
**WebP Conversion:**
- Modern image format with 25-35% smaller file sizes
- Supported by all modern browsers
- Automatic fallback for older browsers

**AVIF Conversion:**
- Next-generation format with 50% smaller file sizes
- Limited browser support (Chrome, Firefox)
- Use only if your audience uses modern browsers

#### Lazy Loading
**What it does:** Load images only when they're about to be visible
**Benefits:** Faster initial page load, reduced bandwidth usage

**Settings:**
- **Images:** Enable for all images (recommended)
- **Videos:** Enable for embedded videos
- **iFrames:** Enable for embedded content
- **Threshold:** How close to viewport before loading (default: 200px)

#### Bulk Optimization
Process existing images in your media library:
1. Go to Performance Optimisation → Image Optimization
2. Click "Analyze Images" to see optimization potential
3. Click "Start Optimization" to process all images
4. Monitor progress in the dashboard

### Advanced Features

#### Database Optimization
- **Auto-cleanup:** Remove spam comments, revisions, and transients
- **Table Optimization:** Optimize database tables for better performance
- **Scheduled Cleanup:** Automatic maintenance on a schedule

#### CDN Integration
- **CloudFlare:** Automatic integration with CloudFlare
- **Custom CDN:** Configure any CDN provider
- **Asset Offloading:** Serve images and files from CDN

## Analytics & Monitoring

### Performance Dashboard
Track your site's performance over time:

#### Charts and Graphs
- **Page Load Time Trends:** See how optimization affects load times
- **Cache Performance:** Monitor cache hit rates and effectiveness
- **Image Optimization Progress:** Track image conversion status
- **User Experience Metrics:** Core Web Vitals tracking

#### Recommendations Engine
Get automated suggestions for improving performance:
- **High Priority:** Critical issues affecting user experience
- **Medium Priority:** Optimization opportunities
- **Low Priority:** Fine-tuning suggestions

### Reports
Generate detailed reports for:
- **Performance Analysis:** Comprehensive performance overview
- **Optimization Impact:** Before/after comparisons
- **Technical Details:** For developers and hosting providers

## Troubleshooting

### Common Issues

#### Site Looks Broken After Enabling Optimization
**Cause:** CSS/JavaScript optimization conflicts
**Solution:**
1. Go to Performance Optimisation → Settings
2. Disable "Combine CSS" and "Defer JavaScript"
3. Clear all caches
4. Test your site functionality
5. Re-enable features one by one to identify the problematic setting

#### Images Not Loading
**Cause:** Lazy loading or image optimization issues
**Solution:**
1. Disable lazy loading temporarily
2. Check if images load normally
3. If yes, adjust lazy loading threshold
4. If no, check image optimization settings

#### Slow Admin Dashboard
**Cause:** Aggressive caching affecting admin area
**Solution:**
1. Ensure admin pages are excluded from caching
2. Check if object caching is causing issues
3. Temporarily disable optimizations for admin area

#### Cache Not Working
**Symptoms:** No performance improvement, cache hit ratio is 0%
**Solutions:**
1. Check file permissions on cache directory
2. Verify hosting environment supports file caching
3. Check for conflicting caching plugins
4. Review cache exclusion rules

### Debug Mode
Enable debug mode for troubleshooting:
1. Go to Performance Optimisation → Settings → Advanced
2. Enable "Debug Mode"
3. Check debug log for error messages
4. Disable debug mode after troubleshooting

### Getting Help
1. **Documentation:** Check this guide and FAQ
2. **Support Forum:** WordPress.org plugin support forum
3. **Debug Information:** Use the debug mode for technical details
4. **Professional Support:** Consider hiring a WordPress developer for complex issues

## FAQ

### General Questions

**Q: Will this plugin work with my theme?**
A: The plugin is designed to work with all properly coded WordPress themes. The setup wizard tests compatibility and recommends safe settings.

**Q: Can I use this with other caching plugins?**
A: No, you should only use one caching plugin at a time. Disable other caching plugins before activating this one.

**Q: Will this plugin slow down my admin area?**
A: No, the plugin automatically excludes admin pages from optimization to maintain full functionality.

**Q: Is it safe to use on a live site?**
A: Yes, but we recommend testing on a staging site first, especially with advanced optimization features.

### Technical Questions

**Q: Does this plugin work with CDNs?**
A: Yes, the plugin includes CDN integration and can automatically configure popular CDN services.

**Q: What about mobile optimization?**
A: The plugin includes mobile-specific optimizations and can maintain separate caches for mobile devices.

**Q: Can I exclude specific pages from optimization?**
A: Yes, you can exclude pages, posts, or entire sections of your site from various optimization features.

**Q: Does it work with e-commerce sites?**
A: Yes, the plugin includes presets for e-commerce sites and automatically excludes cart, checkout, and account pages from caching.

### Performance Questions

**Q: How much faster will my site be?**
A: Results vary, but most sites see 30-70% improvement in load times. The analytics dashboard shows exact measurements.

**Q: Will it reduce my hosting costs?**
A: Yes, by reducing server load and bandwidth usage, you may be able to use a smaller hosting plan.

**Q: What about SEO benefits?**
A: Faster sites rank better in search engines. The plugin helps improve Core Web Vitals, which are ranking factors.

## Advanced Configuration

### Custom Cache Rules
Create advanced caching rules for specific content:

```php
// Example: Custom cache expiration for different post types
add_filter( 'wppo_cache_expiration', function( $expiration, $post_type ) {
    switch( $post_type ) {
        case 'product':
            return 3600; // 1 hour for products
        case 'news':
            return 1800; // 30 minutes for news
        default:
            return $expiration;
    }
}, 10, 2 );
```

### Custom Optimization Rules
Exclude specific files from optimization:

```php
// Example: Exclude specific JavaScript files from minification
add_filter( 'wppo_js_exclude', function( $excluded_files ) {
    $excluded_files[] = 'jquery.min.js';
    $excluded_files[] = 'custom-slider.js';
    return $excluded_files;
} );
```

### Performance Monitoring Hooks
Add custom performance tracking:

```php
// Example: Track custom metrics
add_action( 'wppo_performance_tracking', function() {
    // Your custom tracking code
    wppo_track_metric( 'custom_metric', $value );
} );
```

### Integration with Other Plugins

#### WooCommerce
- Automatic exclusion of cart, checkout, and account pages
- Product image optimization
- Cache warming for product categories

#### Contact Form 7
- Exclusion of form submission pages from caching
- Optimization of form assets

#### Yoast SEO
- Compatible with XML sitemaps
- Preserves SEO meta tags during optimization

### Server-Level Optimizations
For advanced users with server access:

#### Nginx Configuration
```nginx
# Enable gzip compression
gzip on;
gzip_types text/css application/javascript image/svg+xml;

# Browser caching
location ~* \.(css|js|png|jpg|jpeg|gif|svg|woff|woff2)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
}
```

#### Apache Configuration
```apache
# Enable compression
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/css application/javascript
</IfModule>

# Browser caching
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/css "access plus 1 year"
    ExpiresByType application/javascript "access plus 1 year"
</IfModule>
```

---

## Support and Updates

### Getting Support
- **Documentation:** This guide covers most common scenarios
- **WordPress.org Forum:** Community support and discussions
- **GitHub Issues:** Report bugs and request features
- **Professional Support:** Available for complex implementations

### Staying Updated
- **Automatic Updates:** Enable automatic updates in WordPress
- **Changelog:** Review changes before updating
- **Backup:** Always backup before major updates
- **Testing:** Test updates on staging sites first

### Contributing
The plugin is open source and welcomes contributions:
- **Bug Reports:** Help improve the plugin by reporting issues
- **Feature Requests:** Suggest new features and improvements
- **Code Contributions:** Submit pull requests on GitHub
- **Documentation:** Help improve this documentation

---

*Last updated: December 2024*
*Plugin version: 2.0.0*