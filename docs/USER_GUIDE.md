# Performance Optimisation Plugin - User Guide

## Table of Contents
1. [Getting Started](#getting-started)
2. [Setup Wizard](#setup-wizard)
3. [Admin Dashboard](#admin-dashboard)
4. [Configuration Guide](#configuration-guide)
5. [Frequently Asked Questions](#frequently-asked-questions)
6. [Best Practices](#best-practices)
7. [Troubleshooting](#troubleshooting)

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

#### 📈 Monitor Tab
**Performance Analysis**
- Real-time PageSpeed Insights
- Core Web Vitals assessment (LCP, FID, CLS)
- Detailed score breakdown (Performance, Accessibility, SEO)
- Historical performance tracking

**Page-by-Page Analysis**
- Analyze specific URLs
- View resource breakdown by type (JS, CSS, Images)
- Identify largest assets
- actionable optimization suggestions

**Asset Manager**
- View all enqueued scripts and styles
- Identify optimization candidates (large/unminified files)
- Analyze third-party resource impact

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

---

## Frequently Asked Questions

### General Questions

#### What is Performance Optimisation?
Performance Optimisation is a comprehensive WordPress plugin that improves your website's speed and performance through caching, file optimization, image compression, and advanced analytics. It includes features like lazy loading, minification, and automated performance monitoring.

#### Is it free?
Yes, Performance Optimisation is completely free and open-source under the GPL license. All features are available at no cost.

#### Will it work with my theme?
The plugin is designed to work with all properly coded WordPress themes. The setup wizard automatically tests compatibility and recommends safe settings for your specific theme.

#### Can I use it with other caching plugins?
No, you should only use one caching plugin at a time to avoid conflicts. Please deactivate other caching plugins (W3 Total Cache, WP Super Cache, WP Rocket, etc.) before activating Performance Optimisation.

#### Will it slow down my admin area?
No, the plugin automatically excludes WordPress admin pages from optimization to maintain full functionality and speed in the backend.

---

### Installation and Setup

#### How do I install the plugin?
1. **From WordPress Admin:** Plugins → Add New, search for "Performance Optimisation"
2. **Manual Upload:** Download and upload via Plugins → Add New → Upload Plugin
3. **FTP Upload:** Extract to `/wp-content/plugins/` and activate

#### What happens after activation?
You'll be redirected to the Setup Wizard, which will analyze your site, detect your hosting environment, recommend optimal settings, and configure basic optimizations.

#### Can I skip the setup wizard?
Yes, but the wizard provides optimized settings based on your specific site configuration, so it's recommended for most users.

#### How do I reset to default settings?
Go to Performance Optimisation → Settings → Advanced and click "Reset to Defaults".

---

### Performance and Results

#### How much faster will my site be?
Most sites see:
- **30-70% improvement** in page load times
- **50-80% reduction** in file sizes
- **Improved Core Web Vitals** scores
- **Better search engine rankings**

#### Does it work with e-commerce sites?
Yes! The plugin includes specific e-commerce optimizations:
- Automatic exclusion of cart/checkout pages from caching
- Product image optimization
- WooCommerce compatibility

#### What about mobile optimization?
Includes mobile-specific features:
- Separate mobile caching
- Mobile image optimization
- Responsive image generation

---

### Compatibility

#### Which WordPress versions are supported?
- **Minimum:** WordPress 6.2
- **Tested up to:** WordPress 6.4+
- **Recommended:** Latest WordPress version

#### What PHP versions are supported?
- **Minimum:** PHP 7.4
- **Recommended:** PHP 8.0 or higher
- **Tested up to:** PHP 8.3

#### Does it work with multisite?
Yes, the plugin supports WordPress multisite installations. Each site can have its own optimization settings.

#### Which hosting providers work best?
Works with all providers, but performs best with:
- VPS or dedicated servers
- Hosts with SSD storage
- Hosts supporting object caching (Redis/Memcached)
- Modern PHP versions

---

### Troubleshooting Common Questions

#### My site looks broken after enabling optimization
This usually happens due to CSS/JavaScript conflicts:
1. Disable "Combine CSS" and "Defer JavaScript"
2. Clear all caches
3. Test your site
4. Re-enable features one by one

#### Images are not loading
This is typically a lazy loading issue:
1. Disable lazy loading temporarily
2. If images load, adjust the loading threshold
3. Exclude above-the-fold images

#### Cache is not working
Check:
- File permissions on wp-content/cache directory
- No conflicting caching plugins active
- Server configuration supports file caching

#### Performance is not improving
Consider:
- Hosting limitations (shared hosting restrictions)
- Theme performance issues
- Too many active plugins
- External factors (third-party scripts)

---

### Advanced Usage

#### Can I customize optimization rules?
Yes, advanced users can customize through:
- Settings panels
- WordPress hooks and filters
- Exclusion lists
- Custom rules

#### Is there an API?
Yes, the plugin includes a REST API for performance data retrieval, configuration management, and analytics export. See [API Reference](API_REFERENCE.md).

#### Can I use it with a CDN?
Yes, includes CDN integration for CloudFlare, MaxCDN/StackPath, and custom CDNs.

#### How do I optimize for Core Web Vitals?
The plugin automatically optimizes for:
- **LCP:** Image optimization and critical CSS
- **FID:** JavaScript optimization and deferring
- **CLS:** Proper image dimensions and font loading

---

### Support and Updates

#### How often is it updated?
- **Security updates:** As needed (immediately for critical issues)
- **Feature updates:** Monthly or bi-monthly
- **Compatibility updates:** With each major WordPress release

#### Where can I get help?
1. Check [User Guide](USER_GUIDE.md) and [Troubleshooting](#troubleshooting)
2. Visit [WordPress.org Forum](https://wordpress.org/support/plugin/performance-optimisation/)
3. Open an issue on [GitHub](https://github.com/nilesh-32236/performance-optimisation/issues)

#### How do I report a bug?
1. Check documentation to ensure it's not expected behavior
2. Test with default theme and minimal plugins
3. Report on WordPress.org forum or GitHub with detailed information

---

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
