# Frequently Asked Questions (FAQ)

## General Questions

### What is Performance Optimisation?
Performance Optimisation is a comprehensive WordPress plugin that improves your website's speed and performance through caching, file optimization, image compression, and advanced analytics. It includes features like lazy loading, minification, CDN integration, and automated performance monitoring.

### Is it free?
Yes, Performance Optimisation is completely free and open-source under the GPL license. All features are available at no cost.

### Will it work with my theme?
The plugin is designed to work with all properly coded WordPress themes. The setup wizard automatically tests compatibility and recommends safe settings for your specific theme.

### Can I use it with other caching plugins?
No, you should only use one caching plugin at a time to avoid conflicts. Please deactivate other caching plugins (W3 Total Cache, WP Super Cache, WP Rocket, etc.) before activating Performance Optimisation.

### Is it safe to use on a live website?
Yes, but we recommend testing on a staging site first, especially when enabling advanced optimization features. The plugin includes a "Safe Mode" that applies only the most compatible optimizations.

### Will it slow down my admin area?
No, the plugin automatically excludes WordPress admin pages from optimization to maintain full functionality and speed in the backend.

## Installation and Setup

### How do I install the plugin?
1. **From WordPress Admin:** Go to Plugins → Add New, search for "Performance Optimisation", install and activate
2. **Manual Upload:** Download the plugin, go to Plugins → Add New → Upload Plugin, choose the file and activate
3. **FTP Upload:** Extract the plugin to `/wp-content/plugins/` directory and activate from admin

### What happens after activation?
After activation, you'll be redirected to the Setup Wizard, which will:
- Analyze your site's current performance
- Detect your hosting environment
- Recommend optimal settings
- Configure basic optimizations
- Set up performance monitoring

### Can I skip the setup wizard?
Yes, you can skip the wizard and configure settings manually. However, the wizard provides optimized settings based on your specific site configuration, so it's recommended for most users.

### How do I reset to default settings?
Go to Performance Optimisation → Settings → Advanced and click "Reset to Defaults". This will restore all settings to their initial state.

## Features and Functionality

### What optimization features are included?

#### Caching
- **Page Caching:** Store complete HTML pages for faster delivery
- **Object Caching:** Cache database queries and PHP objects
- **Browser Caching:** Instruct browsers to cache static files

#### File Optimization
- **CSS Minification:** Remove unnecessary characters from CSS files
- **JavaScript Minification:** Compress JavaScript files
- **HTML Compression:** Remove whitespace from HTML output
- **File Combination:** Merge multiple CSS/JS files to reduce HTTP requests

#### Image Optimization
- **Format Conversion:** Convert images to WebP and AVIF formats
- **Lazy Loading:** Load images only when they're about to be visible
- **Compression:** Reduce image file sizes without quality loss
- **Responsive Images:** Generate multiple sizes for different devices

#### Advanced Features
- **Critical CSS:** Inline above-the-fold CSS for faster rendering
- **Database Optimization:** Clean up and optimize database tables
- **CDN Integration:** Integrate with content delivery networks
- **Performance Analytics:** Monitor and track performance metrics

### How much faster will my site be?
Results vary depending on your current setup, but most sites see:
- **30-70% improvement** in page load times
- **50-80% reduction** in file sizes through optimization
- **Improved Core Web Vitals** scores
- **Better search engine rankings** due to faster loading

The analytics dashboard provides exact measurements of your improvements.

### Does it work with e-commerce sites?
Yes, the plugin includes specific optimizations for e-commerce:
- Automatic exclusion of cart, checkout, and account pages from caching
- Product image optimization
- Cache warming for product categories
- WooCommerce compatibility

### What about mobile optimization?
The plugin includes mobile-specific features:
- Separate mobile caching
- Mobile image optimization
- Responsive image generation
- Mobile performance monitoring

## Compatibility

### Which WordPress versions are supported?
- **Minimum:** WordPress 6.2
- **Tested up to:** WordPress 6.4
- **Recommended:** Latest WordPress version

### What PHP versions are supported?
- **Minimum:** PHP 7.4
- **Recommended:** PHP 8.0 or higher
- **Tested up to:** PHP 8.3

### Does it work with multisite?
Yes, the plugin supports WordPress multisite installations. Each site in the network can have its own optimization settings.

### Which hosting providers work best?
The plugin works with all hosting providers, but performs best with:
- **VPS or dedicated servers** (more control over optimization)
- **Hosts with SSD storage** (faster file access)
- **Hosts supporting object caching** (Redis/Memcached)
- **Modern PHP versions** (better performance)

### Popular hosting compatibility:
- ✅ **SiteGround** - Excellent compatibility
- ✅ **WP Engine** - Works well, some features may overlap
- ✅ **Kinsta** - Good compatibility
- ✅ **Bluehost** - Compatible with basic features
- ✅ **GoDaddy** - Works on most plans
- ✅ **HostGator** - Compatible
- ⚠️ **Shared hosting** - Limited by hosting restrictions

## Performance and Analytics

### How do I check if it's working?
1. **Performance Dashboard:** Check the main dashboard for performance metrics
2. **Before/After Comparison:** The analytics show improvement over time
3. **Speed Testing Tools:** Use GTmetrix, Google PageSpeed Insights, or Pingdom
4. **Cache Hit Ratio:** Should be above 80% for good performance

### What is the Performance Score?
The Performance Score (0-100) is calculated based on:
- **Page load times** (40% weight)
- **Cache effectiveness** (30% weight)
- **Optimization coverage** (20% weight)
- **Core Web Vitals** (10% weight)

**Score Ranges:**
- **90-100:** Excellent performance
- **70-89:** Good performance
- **50-69:** Needs improvement
- **Below 50:** Poor performance, optimization required

### How often should I check analytics?
- **Daily:** During initial setup and optimization
- **Weekly:** For ongoing monitoring
- **Monthly:** For performance reports and trend analysis
- **After changes:** Always check after making configuration changes

### Can I export performance data?
Yes, you can export analytics data in multiple formats:
- **CSV:** For spreadsheet analysis
- **JSON:** For technical analysis
- **PDF Reports:** For sharing with stakeholders

## Troubleshooting

### My site looks broken after enabling optimization
This usually happens due to CSS/JavaScript optimization conflicts:
1. Go to Settings → File Optimization
2. Disable "Combine CSS" and "Defer JavaScript"
3. Clear all caches
4. Test your site
5. Re-enable features one by one to identify the issue

### Images are not loading
This is typically a lazy loading issue:
1. Disable lazy loading temporarily
2. If images load normally, adjust the loading threshold
3. Exclude above-the-fold images from lazy loading
4. Check browser console for JavaScript errors

### The plugin is not improving performance
Several factors could cause this:
1. **Hosting limitations:** Shared hosting may have restrictions
2. **Theme issues:** Some themes are inherently slow
3. **Plugin conflicts:** Too many plugins can negate benefits
4. **External factors:** Third-party scripts, large databases, etc.

### How do I get help?
1. **Check documentation:** User Guide and Troubleshooting Guide
2. **WordPress.org forum:** Community support
3. **Debug mode:** Enable for technical error information
4. **Professional support:** Available for complex issues

## Advanced Usage

### Can I customize the optimization rules?
Yes, advanced users can customize optimization through:
- **Settings panels:** Comprehensive configuration options
- **WordPress hooks:** For developers to modify behavior
- **Exclusion lists:** Exclude specific files or pages
- **Custom rules:** Create advanced caching and optimization rules

### Is there an API?
Yes, the plugin includes a REST API for:
- Performance data retrieval
- Configuration management
- Analytics export
- Integration with other tools

### Can I use it with a CDN?
Yes, the plugin includes CDN integration for:
- **CloudFlare:** Automatic configuration
- **MaxCDN/StackPath:** Easy setup
- **Custom CDN:** Manual configuration options
- **Asset offloading:** Serve static files from CDN

### How do I optimize for Core Web Vitals?
The plugin automatically optimizes for Core Web Vitals:
- **LCP (Largest Contentful Paint):** Image optimization and critical CSS
- **FID (First Input Delay):** JavaScript optimization and deferring
- **CLS (Cumulative Layout Shift):** Proper image dimensions and font loading

## Security and Privacy

### Is my data safe?
Yes, the plugin:
- **Stores data locally** on your server
- **No external data transmission** except for CDN integration (if enabled)
- **No tracking or analytics** sent to external servers
- **Open source code** available for review

### Does it affect website security?
The plugin enhances security by:
- **Reducing server load** (fewer resources for attacks to exploit)
- **Hiding server response times** (makes fingerprinting harder)
- **Following WordPress security best practices**
- **Regular security updates**

### What permissions does it need?
The plugin requires standard WordPress permissions:
- **File system access:** For caching and optimization
- **Database access:** For storing settings and analytics
- **Admin capabilities:** For configuration (manage_options)

## Updates and Support

### How often is it updated?
- **Security updates:** As needed (immediately for critical issues)
- **Feature updates:** Monthly or bi-monthly
- **Compatibility updates:** With each major WordPress release
- **Bug fixes:** Weekly or as reported

### Will updates break my site?
Updates are thoroughly tested, but we recommend:
- **Backup before updating**
- **Test on staging sites** for major updates
- **Review changelog** before updating
- **Monitor site** after updates

### How do I stay informed about updates?
- **WordPress admin notifications**
- **Plugin changelog**
- **WordPress.org plugin page**
- **Email notifications** (if subscribed)

### What if I find a bug?
1. **Check documentation** to ensure it's not expected behavior
2. **Test with default theme** and minimal plugins
3. **Report on WordPress.org forum** with detailed information
4. **Include debug information** and steps to reproduce

## Migration and Compatibility

### Can I migrate from other caching plugins?
Yes, the plugin can import settings from:
- **W3 Total Cache**
- **WP Super Cache**
- **WP Rocket** (basic settings)
- **LiteSpeed Cache**

### How do I migrate hosting providers?
1. **Export settings** from current site
2. **Set up plugin** on new hosting
3. **Import settings**
4. **Test and adjust** for new hosting environment
5. **Update DNS** when ready

### What about plugin conflicts?
Common conflicts and solutions:
- **Other caching plugins:** Deactivate before using this plugin
- **Security plugins:** May need configuration adjustments
- **SEO plugins:** Generally compatible, test functionality
- **Page builders:** Usually compatible, test optimization settings

## Business and Legal

### Can I use it on client sites?
Yes, the GPL license allows:
- **Commercial use** on client websites
- **Modification** for specific needs
- **Distribution** with your services
- **White-labeling** (with proper attribution)

### Is there premium support?
While the plugin is free, premium support options include:
- **Priority support** for urgent issues
- **Custom configuration** assistance
- **Performance consulting** services
- **Custom development** for specific needs

### What's the license?
Performance Optimisation is licensed under **GPL v2 or later**, which means:
- **Free to use** for any purpose
- **Open source** code available
- **Modification allowed**
- **Distribution permitted**
- **No warranty** (use at your own risk)

---

## Still Have Questions?

### Quick Links
- **[User Guide](user-guide.md)** - Comprehensive documentation
- **[Troubleshooting Guide](troubleshooting.md)** - Common issues and solutions
- **WordPress.org Forum** - Community support
- **Plugin Settings** - In-app help and tooltips

### Contact Information
- **Support Forum:** WordPress.org plugin support
- **Bug Reports:** GitHub issues
- **Feature Requests:** WordPress.org forum
- **Professional Services:** Available through WordPress.org

---

*This FAQ is regularly updated. Last updated: December 2024*