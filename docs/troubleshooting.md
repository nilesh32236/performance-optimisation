# Troubleshooting Guide

## Quick Diagnostic Steps

### 1. Check Plugin Status
1. Go to **Performance Optimisation → Dashboard**
2. Look for any red warning indicators
3. Check the "System Status" section for conflicts

### 2. Clear All Caches
1. Click **"Clear All Caches"** in the dashboard
2. Wait 30 seconds
3. Test your site again

### 3. Disable Optimizations Temporarily
1. Go to **Performance Optimisation → Settings**
2. Click **"Safe Mode"** to disable aggressive optimizations
3. Test if the issue persists

## Common Issues and Solutions

### Site Layout Broken

#### Symptoms
- CSS styles not loading correctly
- Layout appears broken or unstyled
- Elements overlapping or misaligned

#### Causes and Solutions

**CSS Minification Issues:**
1. Go to **Settings → File Optimization**
2. Disable **"Minify CSS"**
3. Clear caches and test
4. If fixed, the issue is with CSS minification

**CSS Combination Problems:**
1. Disable **"Combine CSS Files"**
2. Clear caches
3. Test your site
4. If fixed, some CSS files have dependencies that break when combined

**Critical CSS Issues:**
1. Disable **"Generate Critical CSS"**
2. Clear caches
3. If this fixes the issue, your theme may not be compatible with critical CSS

#### Step-by-Step Fix
```
1. Performance Optimisation → Settings → File Optimization
2. Uncheck "Combine CSS Files"
3. Uncheck "Generate Critical CSS"
4. Keep "Minify CSS" enabled (usually safe)
5. Save settings
6. Clear all caches
7. Test your site
8. If still broken, disable "Minify CSS" as well
```

### JavaScript Functionality Broken

#### Symptoms
- Interactive elements not working (sliders, dropdowns, forms)
- JavaScript errors in browser console
- AJAX requests failing

#### Causes and Solutions

**JavaScript Minification:**
1. Disable **"Minify JavaScript"**
2. Clear caches and test
3. If fixed, add problematic scripts to exclusion list

**JavaScript Deferring:**
1. Disable **"Defer JavaScript Loading"**
2. Clear caches
3. Test functionality
4. If fixed, exclude critical scripts from deferring

**JavaScript Combination:**
1. Disable **"Combine JavaScript Files"**
2. Clear caches
3. Test site functionality

#### Excluding Specific Files
```
1. Go to Settings → File Optimization → Advanced
2. In "JavaScript Exclusions" field, add:
   - jquery.min.js (if jQuery issues)
   - your-theme-name.js (theme-specific scripts)
   - plugin-specific.js (problematic plugin scripts)
3. Save and clear caches
```

### Images Not Loading

#### Symptoms
- Images appear as broken links
- Images load very slowly or not at all
- Lazy loading not working

#### Lazy Loading Issues
1. Go to **Settings → Image Optimization**
2. Disable **"Enable Lazy Loading"**
3. Clear caches and test
4. If images load normally, adjust lazy loading settings:
   - Increase **"Loading Threshold"** to 500px
   - Disable lazy loading for **"Above the Fold Images"**

#### Image Format Conversion Problems
1. Disable **"Convert to WebP"** and **"Convert to AVIF"**
2. Clear caches
3. If images load normally, the issue is with format conversion
4. Check if your server supports image conversion libraries

#### CDN Integration Issues
1. Go to **Settings → CDN**
2. Temporarily disable CDN integration
3. Clear caches and test
4. If images load, check CDN configuration

### Slow Admin Dashboard

#### Symptoms
- WordPress admin loads slowly
- Plugin settings pages are slow
- Media library takes long to load

#### Solutions

**Disable Admin Optimizations:**
1. Go to **Settings → Advanced**
2. Enable **"Exclude Admin Area from Optimization"**
3. Clear caches

**Object Cache Issues:**
1. Disable **"Object Caching"** temporarily
2. Test admin performance
3. If improved, check object cache configuration

**Database Optimization:**
1. Go to **Tools → Database Optimization**
2. Run **"Quick Cleanup"**
3. Avoid running during peak hours

### Cache Not Working

#### Symptoms
- No performance improvement after enabling caching
- Cache hit ratio shows 0%
- Pages still load slowly

#### File Permission Issues
```bash
# Check cache directory permissions (via SSH/FTP)
wp-content/cache/wppo/ should be writable (755 or 775)

# Fix permissions if needed
chmod 755 wp-content/cache/
chmod 755 wp-content/cache/wppo/
```

#### Conflicting Plugins
1. **Deactivate other caching plugins:**
   - W3 Total Cache
   - WP Super Cache
   - WP Rocket
   - LiteSpeed Cache

2. **Check for conflicting plugins:**
   - Security plugins with caching features
   - CDN plugins
   - Optimization plugins

#### Server Configuration
1. **Check .htaccess file** for conflicting rules
2. **Verify hosting environment** supports file caching
3. **Contact hosting provider** if issues persist

### Performance Not Improving

#### Symptoms
- Site still loads slowly after optimization
- Performance score hasn't improved
- No noticeable speed increase

#### Diagnostic Steps

**1. Run Performance Test:**
```
1. Go to Dashboard → Performance Test
2. Click "Run Full Analysis"
3. Review recommendations
4. Check for external factors (hosting, plugins, theme)
```

**2. Check Hosting Environment:**
- **Shared hosting:** May have resource limitations
- **Server location:** Should be close to your audience
- **PHP version:** Use PHP 8.0+ for better performance
- **Database:** Check for slow queries

**3. Identify Bottlenecks:**
```
1. Use browser developer tools
2. Check Network tab for slow-loading resources
3. Look for large images or files
4. Identify third-party scripts causing delays
```

**4. External Factors:**
- **Theme performance:** Some themes are inherently slow
- **Plugin conflicts:** Too many plugins can slow down the site
- **Database size:** Large databases need optimization
- **External services:** Social media widgets, analytics, ads

### Mobile Performance Issues

#### Symptoms
- Site loads slowly on mobile devices
- Mobile performance score is low
- Different behavior on mobile vs desktop

#### Solutions

**Enable Mobile-Specific Optimizations:**
1. Go to **Settings → Mobile Optimization**
2. Enable **"Separate Mobile Cache"**
3. Enable **"Mobile Image Optimization"**
4. Adjust **"Mobile Lazy Loading Threshold"**

**Check Mobile-Specific Issues:**
- Large images not optimized for mobile
- Desktop-only JavaScript running on mobile
- Heavy fonts or CSS affecting mobile performance

### Plugin Conflicts

#### Identifying Conflicts

**1. Plugin Conflict Test:**
```
1. Deactivate all other plugins except Performance Optimisation
2. Test if the issue persists
3. If resolved, reactivate plugins one by one
4. Identify the conflicting plugin
```

**2. Theme Conflict Test:**
```
1. Switch to a default WordPress theme (Twenty Twenty-Four)
2. Test the functionality
3. If it works, the issue is theme-related
```

#### Common Conflicting Plugins
- **Other caching plugins** (must be deactivated)
- **Security plugins** with caching features
- **CDN plugins** (configure integration instead)
- **Image optimization plugins** (may conflict with image features)
- **Minification plugins** (disable their optimization features)

### Database Issues

#### Symptoms
- Slow query performance
- High database usage
- Timeout errors

#### Solutions

**1. Database Cleanup:**
```
1. Go to Tools → Database Optimization
2. Review items to be cleaned
3. Run "Safe Cleanup" first
4. Monitor performance improvement
```

**2. Query Optimization:**
```
1. Enable "Query Monitoring" in Advanced settings
2. Identify slow queries
3. Add database indexes if needed
4. Consider upgrading hosting plan
```

### Memory Issues

#### Symptoms
- "Fatal error: Allowed memory size exhausted"
- Plugin deactivates automatically
- White screen of death

#### Solutions

**1. Increase PHP Memory Limit:**
```php
// Add to wp-config.php
ini_set('memory_limit', '256M');

// Or add to .htaccess
php_value memory_limit 256M
```

**2. Optimize Plugin Settings:**
```
1. Disable memory-intensive features temporarily:
   - Image bulk optimization
   - Critical CSS generation
   - Advanced database optimization
2. Process optimizations in smaller batches
```

## Advanced Troubleshooting

### Debug Mode

**Enable Debug Mode:**
1. Go to **Settings → Advanced → Debug**
2. Enable **"Debug Mode"**
3. Enable **"Log Debug Information"**
4. Reproduce the issue
5. Check debug log for error messages

**Debug Log Location:**
```
wp-content/debug.log
wp-content/plugins/performance-optimisation/logs/debug.log
```

### Browser Developer Tools

**Check Console Errors:**
1. Open browser developer tools (F12)
2. Go to Console tab
3. Look for JavaScript errors (red messages)
4. Note which files are causing errors

**Network Analysis:**
1. Go to Network tab in developer tools
2. Reload the page
3. Sort by "Time" to see slowest resources
4. Check for failed requests (red status codes)

### Server Logs

**Check Error Logs:**
```bash
# Common log locations
/var/log/apache2/error.log
/var/log/nginx/error.log
~/logs/error.log (cPanel hosting)
```

**Look for:**
- PHP errors related to the plugin
- Memory limit errors
- File permission errors
- Database connection issues

### Performance Testing Tools

**Recommended Tools:**
1. **GTmetrix** - Comprehensive performance analysis
2. **Google PageSpeed Insights** - Core Web Vitals
3. **Pingdom** - Speed testing from multiple locations
4. **WebPageTest** - Advanced performance testing

**What to Check:**
- **Time to First Byte (TTFB)** - Server response time
- **First Contentful Paint (FCP)** - First visible content
- **Largest Contentful Paint (LCP)** - Main content loading
- **Cumulative Layout Shift (CLS)** - Visual stability

## Getting Help

### Before Contacting Support

**Gather Information:**
1. **WordPress version**
2. **Plugin version**
3. **Active theme name**
4. **List of active plugins**
5. **Hosting provider and plan**
6. **PHP version**
7. **Error messages** (exact text)
8. **Steps to reproduce** the issue

### Support Channels

**1. Documentation:**
- User Guide
- FAQ section
- Video tutorials

**2. Community Support:**
- WordPress.org plugin forum
- Community discussions
- User-contributed solutions

**3. Professional Support:**
- Priority support for complex issues
- Custom configuration assistance
- Performance optimization consulting

### Temporary Workarounds

**If You Need to Disable the Plugin:**
1. **Via WordPress Admin:**
   - Go to Plugins → Installed Plugins
   - Deactivate Performance Optimisation

2. **Via FTP/File Manager:**
   - Rename plugin folder from `performance-optimisation` to `performance-optimisation-disabled`

3. **Emergency Deactivation:**
   - Add to wp-config.php: `define('WPPO_DISABLE', true);`

**Safe Mode Activation:**
```php
// Add to wp-config.php for emergency safe mode
define('WPPO_SAFE_MODE', true);
```

This will disable all optimizations while keeping the plugin active for troubleshooting.

---

## Prevention Tips

### Regular Maintenance
1. **Update regularly** - Keep plugin, WordPress, and themes updated
2. **Test changes** - Use staging sites for testing
3. **Monitor performance** - Check analytics regularly
4. **Backup before changes** - Always backup before major changes

### Best Practices
1. **Start with safe settings** - Use recommended presets initially
2. **Enable features gradually** - Add optimizations one at a time
3. **Test thoroughly** - Check all site functionality after changes
4. **Monitor after changes** - Watch for issues in the first 24 hours

### Performance Monitoring
1. **Set up alerts** - Get notified of performance issues
2. **Regular testing** - Run performance tests monthly
3. **User feedback** - Monitor user reports of issues
4. **Analytics review** - Check performance trends regularly

---

*Need more help? Check our [User Guide](user-guide.md) or visit the [FAQ](faq.md) section.*