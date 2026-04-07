=== Performance Optimisation ===
Contributors: nilesh912
Tags: performance, optimization, cache, minify, image optimization
Requires at least: 6.2
Requires PHP: 7.4
Tested up to: 6.7
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A plugin to enhance website performance by managing cache, minifying JavaScript, CSS, and optimizing images.

== Description ==

Performance Optimisation helps you optimize your website's speed by offering features like cache management, JavaScript and CSS minification, image conversion, lazy loading, preloading, and more. With an intuitive dashboard, detailed settings, and useful tools, it simplifies performance enhancement for your website.

**Features:**

 - Dashboard with an overview of cache, JavaScript, CSS, and image optimization status.
 - Cache management tools, including size display and a "Clear Cache" button.
 - JavaScript & CSS Optimization: Minify, combine, and exclude specific files.
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
 - Monitor image optimization (WebP/AVIF status).  
 - Review recent plugin activities.  

2. **File Optimization Settings**  
 - Minify JavaScript, CSS, and HTML.  
 - Combine CSS and exclude specific files.  
 - Defer and delay JavaScript loading.  

3. **Preload Settings**  
 - Enable cache preloading.  
 - Preconnect to origins and prefetch DNS.  
 - Preload fonts, CSS, and images.  

4. **Image Optimization Settings**  
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
Advanced image optimization and lazy loading.
Preloading settings for cache, fonts, and images.
Import/export settings tools.

== Frequently Asked Questions ==
 = How do I optimize images using this plugin? =
 Go to the Image Optimization Settings tab, enable image conversion, and choose the format (WebP, AVIF, or both). Click "Optimize Now" to start the process.

 = Can I exclude specific JavaScript or CSS files from minification? =
 Yes, in the File Optimization Settings tab, use the provided text areas to list files you want to exclude.

 = Does the plugin support lazy loading for images? =
 Yes, lazy loading can be enabled in the Image Optimization Settings tab. You can also use SVG placeholders for better performance.

 = How can I import/export plugin settings? =
 Use the Tools section to export your current settings or import settings from another instance.

== Upgrade Notice ==

= 1.1.1 (2026-04-06) =
Minor release with JS performance optimizations and security hardening.

= 1.1.0 (2026-04-05) =
Stable v1.1.0 release with security hardening and user interface refinements.

= 1.0.0 (2024-12-18) =
Initial release with core performance features.