=== Performance Optimisation ===
Contributors: nilesh912
Tags: performance, optimization, cache, minify, image optimization
Requires at least: 5.0
Requires PHP: 7.4
Tested up to: 6.7
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A plugin to enhance website performance by managing cache, minifying JavaScript, CSS, and optimizing images.

== Description ==

Performance Optimisation helps you optimize your website's speed by offering features like cache management, JavaScript and CSS minification, image conversion, lazy loading, preloading, and more. With an intuitive dashboard, detailed settings, and useful tools, it simplifies performance enhancement for your website.

**Features:**

 - Dashboard with an overview of cache, JavaScript, CSS, and image optimization status.
 - Cache management tools, including size display and a "Clear Cache" button.
 - JavaScript & CSS Optimization: Minify, combine, and exclude specific files.
 - **Inline CSS Minification:** Minifies inline `<style>` elements directly in the rendered HTML for better performance. *(See FAQ for details)*
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

= 1.0.0 =

Initial release with full functionality:
Dashboard overview.
Cache management.
JavaScript, CSS, and HTML optimization.
Advanced image optimization and lazy loading.
Preloading settings for cache, fonts, and images.
Import/export settings tools.

== Frequently Asked Questions ==

 = How does the plugin handle inline CSS? =

 The plugin includes a `minify_inline_css()` function that minifies inline `<style>` elements directly in the rendered HTML. This is essential for optimizing inline CSS added dynamically by themes, plugins, or custom templates.
 
 = Why not use wp_enqueue_style or wp_add_inline_style? =
 
 The `minify_inline_css()` function operates on fully-rendered HTML during the `template_redirect` phase. This allows it to handle inline CSS that has already been embedded into the page. WordPress functions like `wp_enqueue_style` are not applicable for such cases because they focus on pre-registration of CSS files.
 
 **Key Benefits:**
 
 1. **Necessity:** It handles dynamically injected inline CSS that cannot be pre-registered or enqueued.
 2. **Error Handling:** The function preserves the original CSS if an error occurs during minification, ensuring stability.
 3. **Performance Impact:** Minifying inline CSS reduces the size of the final HTML document, improving page load times.

 = How do I optimize images using this plugin? =
 Go to the Image Optimization Settings tab, enable image conversion, and choose the format (WebP, AVIF, or both). Click "Optimize Now" to start the process.

 = Can I exclude specific JavaScript or CSS files from minification? =
 Yes, in the File Optimization Settings tab, use the provided text areas to list files you want to exclude.

 = Does the plugin support lazy loading for images? =
 Yes, lazy loading can be enabled in the Image Optimization Settings tab. You can also use SVG placeholders for better performance.

 = How can I import/export plugin settings? =
 Use the Tools section to export your current settings or import settings from another instance.

== Upgrade Notice ==

= 1.0.0 = Initial release. Install to enhance your website's performance.