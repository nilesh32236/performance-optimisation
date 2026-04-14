=== Performance Optimisation ===
Contributors: nilesh912
Tags: performance, optimization, cache, minify, image optimisation
Requires at least: 6.2
Requires PHP: 7.4
Tested up to: 6.9
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight performance toolkit: cache tools, file optimisation, WebP/AVIF conversion, and Core Web Vitals–friendly options—with safe defaults and clear warnings for advanced features.

== Description ==

Performance Optimisation helps you speed up your site with cache management, JavaScript and CSS minification, image conversion (WebP and AVIF), lazy loading, preload hints, and a modern admin UI. It is designed to stay **off by default** for aggressive options (defer/delay JS, WooCommerce asset removal, server rules) so you can enable features gradually and test as you go—similar to how you would tune Autoptimize or a caching stack, but with a focused, dashboard-first workflow.

**Why use this plugin?**

 - **Clear scope:** One place for cache stats, file optimisation, images, preload, and tools—without bundling unrelated features.
 - **Safety-first UX:** Advanced toggles show warnings; WooCommerce-related options remind you to test cart and checkout.
 - **Core Web Vitals & PageSpeed:** Lazy loading, minification, preconnect/prefetch, and image formats help real-world metrics—not just a higher score on a single lab test.

**Features:**

 - Dashboard with an overview of cache, JavaScript, CSS, and image optimisation status.
 - Cache management tools, including size display and a "Clear Cache" button.
 - JavaScript & CSS Optimization: Minify, combine, defer/delay (opt-in), and exclude specific files.
 - Image optimization: Convert images to WebP and AVIF formats.
 - Preload settings for cache, fonts, DNS, and images.
 - Advanced lazy loading options.
 - Import/export plugin settings.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/performance-optimisation` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Configure the settings via the **Performance Optimisation** menu in the WordPress admin panel.

== Usage ==

1. **Dashboard Overview**  
 - View cache size and clear cache.  
 - Check the number of minified JavaScript and CSS files.  
 - Monitor image optimisation (WebP/AVIF status).  
 - Review recent plugin activities.  

2. **File Optimization Settings**  
 - Minify JavaScript, CSS, and HTML.  
 - Combine CSS and exclude specific files.  
 - Defer and delay JavaScript loading.  

3. **Preload Settings**  
 - Enable cache preloading.  
 - Preconnect to origins and prefetch DNS.  
 - Preload fonts, CSS, and images.  

4. **Image Optimisation Settings**  
 - Lazy load images with SVG placeholders.  
 - Convert images to WebP/AVIF formats and exclude specific images.  
 - Preload feature images for selected post types.  

5. **Tools**  
 - Import/export plugin settings for quick setup.

== Composer Libraries ==

This plugin uses the following composer libraries:

 - `voku/html-min` - For HTML minification.
 - `matthiasmullie/minify` - For JavaScript and CSS minification.

Composer configuration:

`
{
	"name": "nilesh/performance-optimisation",
	"description": "A package for performance optimization, including HTML minification and code minification tools.",
	"license": "GPL-2.0-or-later",
	"authors": [
		{
			"name": "nilesh",
			"email": "nilesh.kanzariya912@gmail.com"
		}
	],
	"require": {
		"voku/html-min": "^4.5",
		"matthiasmullie/minify": "^1.3"
	},
	"extra": {
		"cleanup": {
			"dirs": ["bin", "tests", "docs"],
			"exclude": ["*.md", "*.yml", "*.xml", "tests", "docs"]
		}
	}
}
`

== Changelog ==

= 1.2.1 (2026-04-14) =
* Fix: Add `WP_CACHE` to wp-config.php when the constant was previously undefined (correct activation logic).
* Safety: `advanced-cache.php` includes a plugin marker; do not overwrite or delete another plugin’s drop-in.
* UX: Admin notices for foreign drop-in, wp-config issues, competing full-page cache plugins, and a short post-activation welcome notice.
* UI: Stronger warning when enabling WooCommerce asset removal.
* Docs: Expanded readme description, FAQ, and screenshot placeholders.
* Meta: Plugin header `Requires at least` now matches readme.txt (6.2).

= 1.2.0 (2026-04-13) =
* New: Automatic Gzip compression and browser caching for faster page loads.
* New: CDN support — serve static assets from your own CDN domain.
* New: Smarter cache clearing — related pages update automatically when you edit content.
* New: Safety prompts before deleting data, removing images, or importing settings.
* New: Helpful warnings when enabling advanced options like Defer JS or Server Rules.
* New: Plugin UI matches your chosen WordPress admin color scheme.
* Improvement: Faster loading — removed external font dependency.
* Improvement: Better form inputs, loading indicators, and keyboard navigation.
* Improvement: Faster database operations for image processing.
* Security: Fixed several file path security issues.
* Compatibility: Tested up to WordPress 6.9.

= 1.1.4 (2026-04-08) =
* Security: Fixed path traversal vulnerability in the Image Optimisation REST endpoint.
* Security: Added directory traversal protection in URL-to-path resolution.
* Performance: Optimized image queue database writes by caching in memory and flushing once on shutdown.
* Fix: Updated CheckboxOption component to use unique IDs for proper accessibility (label/input association, aria-describedby).

= 1.1.3 (2026-04-07) =
* Fix: Anchored build paths in .distignore to prevent accidental exclusion of vendor files.

= 1.1.2 (2026-04-07) =
* Fix: Cache the Img_Converter instance to reduce PHP overhead during image conversion.
* Fix: Validate and sanitize imported REST API settings before saving.
* Fix: Improve sidebar accessibility and keyboard navigation in the admin UI.
* Update: Use `@wordpress/element` for React rendering compatibility in WordPress.

= 1.1.1 (2026-04-06) =
* Improvement: Optimized JS Defer and Delay loading by caching exclusion lists.
* Improvement: Enhanced backend performance by reducing redundant string parsing.
* Security: Implemented protection against potential directory traversal vulnerabilities.
* Fix: Standardized REST API key sanitization to prevent settings synchronization issues.
* Localization: Added translated ARIA labels for sidebar accessibility.

= 1.1.0 (2026-04-05) =
* Improvement: Visually enhanced the 'File Optimization' settings for easier configuration.
* Improvement: Hardened overall plugin security and input validation.
* Fix: Automatically clear cache when changing permalink settings or switching themes.
* Fix: Prevented unnecessary CSS files from generating on 404 error pages.
* Update: Improved image lazy loading reliability for smoother page rendering.


= 1.0.0 (2024-12-18) =

Initial release with full functionality:
Dashboard overview.
Cache management.
JavaScript, CSS, and HTML optimization.
Advanced image optimisation and lazy loading.
Preloading settings for cache, fonts, and images.
Import/export settings tools.

== Frequently Asked Questions ==

= Will this work with WooCommerce? =
Yes. WooCommerce-specific asset removal is **optional** and off by default. If you enable it, test cart, checkout, and product pages—incorrect URL or handle exclusions can break the storefront.

= Can I use this with another cache plugin (WP Super Cache, LiteSpeed, WP Rocket, etc.)? =
You should run **one** full-page caching solution. This plugin can install a `advanced-cache.php` drop-in when appropriate; if another plugin or your host already manages that file, Performance Optimisation will not replace it and may show an admin notice. Minify/image features may still be usable depending on your stack—test carefully.

= Does this plugin improve Core Web Vitals or PageSpeed Insights? =
It can help when you enable features that address LCP, CLS, and JS blocking (lazy load, minify, preload, modern image formats). Results depend on your theme and other plugins; always measure before and after.

= How do I optimize images using this plugin? =
Go to the Image Optimisation Settings tab, enable image conversion, and choose the format (WebP, AVIF, or both). Click "Optimize Now" to start the process.

= Can I exclude specific JavaScript or CSS files from minification? =
Yes, in the File Optimization Settings tab, use the provided text areas to list files you want to exclude.

= Does the plugin support lazy loading for images? =
Yes, lazy loading can be enabled in the Image Optimisation Settings tab. You can also use SVG placeholders for better performance.

= How can I import/export plugin settings? =
Use the Tools section to export your current settings or import settings from another instance.

== Upgrade Notice ==

= 1.2.1 (2026-04-14) =
Maintenance and trust: aligned WordPress version headers with readme.txt, fixed WP_CACHE setup logic, safer advanced-cache drop-in handling (no overwrite of other plugins’ files), admin notices for activation issues and competing cache plugins, onboarding notice, and WooCommerce warning in UI. See changelog for details.

= 1.2.0 (2026-04-13) =
Major feature release completing the "Cache Core" milestone: .htaccess automation, CDN URL rewriting, and smart cache purging. Includes a full Design System v2.0 with WordPress admin color scheme sync, confirmation dialogs, and polished form controls. Significant security and performance improvements throughout.

= 1.1.4 (2026-04-08) =
Security release with path traversal fixes, image queue performance improvements, and accessibility fixes.

= 1.1.3 (2026-04-07) =
Maintenance release to fix vendor file exclusion in build packages.

= 1.1.1 (2026-04-06) =
Minor release with JS performance optimizations and security hardening.

= 1.1.0 (2026-04-05) =
Stable v1.1.0 release with security hardening and user interface refinements.

= 1.0.0 (2024-12-18) =
Initial release with core performance features.
