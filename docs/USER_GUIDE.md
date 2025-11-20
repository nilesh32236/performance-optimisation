# Performance Optimisation Plugin - User Guide

## Table of Contents
1. [Getting Started](#getting-started)
2. [Setup Wizard](#setup-wizard)
3. [Admin Dashboard](#admin-dashboard)
4. [Configuration Guide](#configuration-guide)
5. [Best Practices](#best-practices)
6. [Troubleshooting](#troubleshooting)

## Getting Started

### First Time Setup
After installing and activating the plugin, you'll be automatically redirected to the Setup Wizard. This guided process will help you configure the plugin for optimal performance on your specific website.

### System Requirements Check
The plugin will automatically check if your server meets the minimum requirements:
- ✅ WordPress 6.2+
- ✅ PHP 7.4+
- ✅ Write permissions
- ✅ Required PHP extensions

## Setup Wizard

### Step 1: Welcome
- Introduction to the plugin features
- System requirements verification
- Backup recommendation

### Step 2: Site Detection
- Automatic site analysis
- Performance baseline measurement
- Current optimization detection

### Step 3: Preset Selection
Choose from three optimization levels:

#### Conservative (Recommended for beginners)
- Basic page caching
- Image lazy loading
- Safe optimizations only
- Minimal risk of conflicts

#### Balanced (Recommended for most sites)
- Page and object caching
- CSS/JS minification
- Image optimization
- Moderate performance gains

#### Aggressive (For advanced users)
- All optimization features
- File combining
- Advanced caching
- Maximum performance gains

### Step 4: Feature Customization
Fine-tune specific features:
- **Cache Preloading**: Automatically generate cache
- **Image Conversion**: WebP/AVIF format conversion
- **Critical CSS**: Inline above-the-fold styles
- **Resource Hints**: DNS prefetch and preconnect

### Step 5: Completion
- Review selected settings
- Apply configuration
- Performance test scheduling

## Admin Dashboard

### Navigation Structure
The admin interface is organized into five main tabs:

#### 🏠 Dashboard Tab
**Performance Overview Cards**
- Performance Score (0-100)
- Average Load Time
- Cache Hit Ratio
- Active Optimizations

**Quick Actions**
- Clear All Cache
- Optimize Images
- Run Performance Test
- Export Settings

**Real-time Monitoring**
- Live performance metrics
- Server resource usage
- Optimization queue status

#### 💾 Caching Tab
**Page Caching**
- Enable/disable page caching
- Cache lifetime settings (TTL)
- Cache exclusion rules
- Cache preloading options

**Object Caching**
- Database query caching
- PHP object caching
- Cache invalidation rules

**Browser Caching**
- Static resource caching
- Cache headers configuration
- Expiration times by file type

#### ⚡ Optimization Tab
**CSS Optimization**
- Minification settings
- File combining options
- Critical CSS inlining
- Unused CSS removal

**JavaScript Optimization**
- Minification and compression
- File combining
- Defer/async loading
- Script optimization

**HTML Optimization**
- HTML minification
- Comment removal
- Whitespace optimization

**Resource Hints**
- DNS prefetch domains
- Preconnect origins
- Preload critical resources

#### 🖼️ Images Tab
**Lazy Loading**
- Image lazy loading
- Video lazy loading
- Placeholder options
- Loading animation

**Format Conversion**
- WebP conversion
- AVIF conversion (advanced)
- Quality settings
- Fallback handling

**Compression**
- Quality slider (50-100%)
- Automatic resizing
- Bulk optimization
- Progress tracking

#### 🔧 Advanced Tab
**WordPress Optimizations**
- Disable emojis
- Disable embeds
- Remove query strings
- Hide WP version

**Database Optimization**
- Clean post revisions
- Remove spam comments
- Optimize database tables
- Scheduled cleanup

**Security Features**
- Disable file editing
- XML-RPC protection
- Security headers

## Configuration Guide

### Recommended Settings by Site Type

#### Blog/Content Site
```
Caching:
✅ Page Caching (1 hour TTL)
✅ Browser Caching
❌ Object Caching (unless high traffic)

Optimization:
✅ CSS Minification
✅ JS Minification
❌ File Combining (may break themes)

Images:
✅ Lazy Loading
✅ WebP Conversion
Quality: 85%

Advanced:
✅ Disable Emojis
✅ Clean Revisions
```

#### E-commerce Site
```
Caching:
✅ Page Caching (30 minutes TTL)
✅ Object Caching
✅ Browser Caching
Exclusions: /checkout/, /cart/, /my-account/

Optimization:
✅ CSS Minification
✅ JS Minification
⚠️ File Combining (test thoroughly)

Images:
✅ Lazy Loading
✅ WebP Conversion
Quality: 90% (product images)

Advanced:
✅ Database Optimization
❌ Aggressive WordPress tweaks
```

#### High-Traffic Site
```
Caching:
✅ Page Caching (2 hours TTL)
✅ Object Caching
✅ Cache Preloading
✅ Browser Caching

Optimization:
✅ All minification
✅ File Combining (if tested)
✅ Critical CSS

Images:
✅ Lazy Loading
✅ WebP + AVIF
Quality: 80%

Advanced:
✅ All WordPress optimizations
✅ Database optimization
```

### Performance Tuning Tips

#### Cache Settings
- **TTL (Time To Live)**: Start with 1 hour, increase gradually
- **Exclusions**: Always exclude dynamic pages (checkout, user accounts)
- **Preloading**: Enable for high-traffic sites with stable content

#### Image Optimization
- **Quality**: 85% for most images, 90% for hero images, 75% for thumbnails
- **Formats**: WebP for compatibility, AVIF for cutting-edge performance
- **Lazy Loading**: Essential for image-heavy sites

#### File Optimization
- **Minification**: Safe for all sites
- **Combining**: Test thoroughly, may break some themes/plugins
- **Critical CSS**: Advanced feature, requires testing

## Best Practices

### Before Making Changes
1. **Create a backup** of your site
2. **Test on staging** environment first
3. **Document current performance** metrics
4. **Enable maintenance mode** during optimization

### Optimization Process
1. **Start conservative** - Enable basic optimizations first
2. **Test thoroughly** - Check all site functionality
3. **Measure impact** - Compare before/after metrics
4. **Gradually increase** - Add more optimizations over time

### Monitoring Performance
1. **Regular checks** - Monitor performance weekly
2. **User feedback** - Watch for user-reported issues
3. **Analytics review** - Check bounce rates and engagement
4. **Speed tests** - Use tools like GTmetrix, PageSpeed Insights

### Maintenance Schedule
- **Daily**: Automatic cache clearing and optimization
- **Weekly**: Performance monitoring and review
- **Monthly**: Database optimization and cleanup
- **Quarterly**: Full performance audit and settings review

## Troubleshooting

### Common Issues and Solutions

#### Site Loading Slowly After Optimization
**Possible Causes:**
- Aggressive minification breaking code
- File combining causing conflicts
- Cache not working properly

**Solutions:**
1. Disable file combining temporarily
2. Check for JavaScript errors in browser console
3. Clear all caches (plugin + browser)
4. Test with default theme

#### Images Not Loading
**Possible Causes:**
- Lazy loading conflicts
- WebP conversion issues
- Server configuration problems

**Solutions:**
1. Disable lazy loading temporarily
2. Check WebP server support
3. Verify image file permissions
4. Test with different image formats

#### Cache Not Working
**Possible Causes:**
- File permission issues
- Conflicting plugins
- Server-level caching conflicts

**Solutions:**
1. Check wp-content directory permissions
2. Deactivate other caching plugins
3. Contact hosting provider about server caching
4. Review cache exclusion rules

#### Admin Interface Issues
**Possible Causes:**
- JavaScript conflicts
- Theme compatibility issues
- Browser caching

**Solutions:**
1. Clear browser cache
2. Disable other plugins temporarily
3. Switch to default WordPress theme
4. Check browser console for errors

### Debug Mode
Enable debug mode for detailed logging:

```php
// Add to wp-config.php
define('WPPO_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check debug log at `/wp-content/debug.log`

### Performance Testing Tools
- **GTmetrix**: Comprehensive performance analysis
- **Google PageSpeed Insights**: Core Web Vitals assessment
- **WebPageTest**: Detailed waterfall analysis
- **Pingdom**: Speed monitoring and alerts

### Getting Help
1. **Check documentation** - Review this guide and FAQ
2. **Search support forum** - Look for similar issues
3. **Create support ticket** - Provide detailed information
4. **Include debug info** - System info and error logs

### Support Information to Include
- WordPress version
- Plugin version
- Active theme
- Other active plugins
- Server configuration (PHP version, memory limit)
- Error messages or screenshots
- Steps to reproduce the issue

---

**Need more help?** Visit our [support forum](https://wordpress.org/support/plugin/performance-optimisation) or [contact us](mailto:support@example.com).
