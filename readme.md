<div align="center">

# Performance Optimisation for WordPress

**Speed up WordPress with page caching, JS/CSS minify, lazy load, WebP/AVIF images, Redis object cache, and database cleanup. Simple and powerful.**

[![WordPress Version](https://img.shields.io/badge/WordPress-6.2+-blue.svg?style=flat-square&logo=wordpress)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-8.2+-777BB4.svg?style=flat-square&logo=php)](https://php.net/)
[![License: GPL v2](https://img.shields.io/badge/License-GPLv2-success.svg?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)

</div>

---

## About The Project

Performance Optimisation is a free, all-in-one speed plugin that makes your WordPress site faster — without the complexity. Page caching, file minification, image optimization, lazy loading, database cleanup, and Redis object cache — all from one clean dashboard.

Most performance plugins either do too little or overwhelm you with dozens of confusing settings. This plugin gives you everything you need to speed up WordPress in one place, with safe defaults and clear explanations for every option.

**Requirements:** WordPress **6.2+** and PHP **8.2+** (same as the [WordPress.org](https://wordpress.org/plugins/performance-optimisation/) listing; the canonical requirements live in `readme.txt`).

**Safe defaults:** Aggressive options (defer/delay JavaScript, WooCommerce asset stripping, server rules) are **off** by default, with clear warnings when you enable them.

---

## What's New in v1.9.0

- **Centralized `content_url()` Static Caching:** High-frequency asset minification loops now reuse static `content_url()` lookups via `Util::cached_content_url()`, keyed per site per request (`get_current_blog_id()`) for multisite safety under `switch_to_blog()`.
- **WordPress 7.1+ Client-Side Media Processing Control:** Added `filter_client_side_supported_mime_types` setting to let site admins select in-browser Web Worker supported image formats, safely intersected with core capabilities.
- **Size-Aware Image Quality & LQIP Isolation:** Integrated WP 7.1+ `wp_get_image_encode_quality()` and WP 6.7–7.0 `wp_image_quality()` for full WebP/AVIF conversions while preserving fixed low-quality (40) for LQIP placeholders.
- **Core Resource Hints Migration:** Preconnect and DNS-prefetch link emission now leverage core's `wp_resource_hints` filter with automatic bare-hostname (`example.com` -> `//example.com`) normalization and `crossorigin` attribute preservation.
- **Inline Combined CSS & Block Styles:** Combined CSS stylesheets can be inlined via `wp_maybe_inline_styles()`, and classic themes now load separate block styles on demand by default on WP 6.9+.

---

## ⚡ Performance Showcase (Before vs After)

Real-world test results measured on a standard WordPress installation (Astra theme, 5 images, comments):

| Metric | Before | After | Improvement |
| :--- | :--- | :--- | :--- |
| **Mobile PageSpeed** | 52 / 100 | **98 / 100** | **+88%** |
| **TTFB (Time to First Byte)** | 680 ms | **45 ms** | **-93%** |
| **LCP (Largest Contentful Paint)** | 4.1 s | **1.2 s** | **-71%** |
| **Total Page Size** | 3.2 MB | **820 KB** | **-74%** |

See the complete methodology and detailed desktop/mobile breakdown in [PERFORMANCE.md](PERFORMANCE.md).

---

## Key Features

### Dashboard Analytics

- **Cache Status:** Monitor cache size and clear cache directly from the overview.
- **Optimization Metrics:** View exact counts of minified JavaScript and CSS files.
- **Image Conversion Status:** Track WebP and AVIF generation status (Completed, Pending, Failed).
- **Activity Log:** Review recent system activities, including plugin activation and cache clearing logs.

### File Optimization Settings

- **Asset Minification:** Minify JavaScript, CSS, and HTML payloads.
- **Combine & Exclude:** Combine CSS files and define strict exclusion rules to prevent visual breakage.
- **Render-Blocking Resolution:** Defer or delay JavaScript execution.
- **Core Tweaks:** Reduce native WordPress bloat by disabling Emojis, Embeds, frontend Dashicons, XML-RPC, and adjusting Heartbeat API frequency.
- **E-Commerce Optimization (opt-in):** Remove WooCommerce CSS and JS on non-store pages when you enable it; the UI warns you to test cart, checkout, and product flows.

### Advanced Preloading Settings

- **Cache Generation:** Enable cache preloading to proactively generate static HTML and GZIP files.
- **Network Routing:** Add preconnect origins and prefetch DNS domains for faster third-party resource loading.
- **Resource Preloading:** Prioritize the loading of fonts, critical CSS, and specific images (now injected with `fetchpriority` hints).
- **Dynamic Feature Images:** Preload feature images for specific post types with configurable exclusions.

### Image Optimization Settings

- **Next-Gen Formats:** Automatically convert images to highly compressed WebP or AVIF formats.
- **Smart Lazy Loading:** Defer offscreen images and `<video>` tags utilizing lightweight SVG placeholders and an active `MutationObserver` to track dynamically injected DOM content.
- **Exclusion Rules:** Limit preloaded image sizes and exclude specific media from lazy loading rules.

### Database Optimization

- **Database Cleanup:** Instantly strip out orphaned metadata, spam comments, and expired transients.
- **Automated Scheduling:** Automatically run cleanup routines Daily, Weekly, or Monthly via WP-Cron.
- **Advanced Revision Control:** Retain precise post revisions based on either their maximum age or minimum number to keep per-post.

### Administrative Tools & WP-CLI

- **Portability:** Import and export plugin settings with a single click for rapid deployment across multiple client sites.
- **WP-CLI Commands:** Manage caching, database cleanup, settings, and object cache from terminal:
  - `wp wppo cache clear [--page=<url>]`
  - `wp wppo database cleanup [--type=<type>]`
  - `wp wppo settings get [<tab>]`
  - `wp wppo settings update <tab> --settings=<json>`
  - `wp wppo object-cache flush`
- **Developer Action Hooks & Filters:** Easily extend plugin behavior with standard WordPress hooks (`wppo_before_cache_clear`, `wppo_after_cache_clear`, `wppo_exclude_delay_js`, `wppo_exclude_defer_js`, `wppo_exclude_minification`, `wppo_cache_page_html`, `wppo_lazyload_iframe_allowed`, `wppo_database_cleanup_completed`). See the full [Developer Hooks Reference](docs/hooks.md).

### Compatibility & Ecosystem

Performance Optimisation is tested and verified compatible with popular themes, page builders, e-commerce platforms, and SEO plugins:
- **Themes:** Astra, GeneratePress, Kadence, OceanWP, Blocksy, Twenty Twenty-Four.
- **Page Builders:** Elementor, Divi, Beaver Builder, WPBakery.
- **E-Commerce:** WooCommerce (cart, checkout, and account pages are automatically excluded from full-page caching).
- **SEO Plugins:** Yoast SEO, Rank Math, All in One SEO, SEOPress (XML sitemaps and feeds bypass caching/minification).

---

## Dependencies & Tech Stack

This plugin leverages modern development practices, utilizing Composer for PHP dependencies and NPM/Webpack for React-based admin interfaces.

- **[voku/html-min](https://github.com/voku/HtmlMin):** PHP library for HTML minification.
- **[matthiasmullie/minify](https://github.com/matthiasmullie/minify):** PHP library for JavaScript and CSS minification.
- **[@wordpress/scripts](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/):** Build tools for modern WordPress Block Editor and React development.
- **UI Components:** Font Awesome Free Solid Icons and React FontAwesome.

---

## Installation & Setup

### For End Users

1. Clone the repository:

   ```bash
   git clone https://github.com/nilesh32236/performance-optimisation.git
   ```

2. Navigate to the plugin directory:
   ```bash
   cd performance-optimisation
   ```
3. Install PHP dependencies via Composer:
   ```bash
   composer install --no-dev
   ```
4. Install Node dependencies:
   ```bash
   npm install
   ```
5. Build the plugin frontend assets:
   ```bash
   npm run build
   ```
6. Upload the compiled plugin folder to your WordPress site's `wp-content/plugins/` directory.
7. Activate the plugin from the **Plugins** menu in WordPress.

### For Developers

1.  Follow the installation steps above.
2.  To start the Webpack development environment and watch for changes:
    ```bash
    npm run start
    ```

---

## Package Configurations

### Composer (`composer.json`)

```json
{
  "name": "nilesh/performance-optimisation",
  "description": "A package for performance optimization, including HTML minification and code minification tools.",
  "license": "GPL-2.0-or-later",
  "authors": [
    {
      "name": "nilesh",
      "email": "nilesh.kanzariya912@gmail.com",
      "homepage": "https://github.com/nilesh32236"
    }
  ],
  "require": {
    "voku/html-min": "^4.5",
    "matthiasmullie/minify": "^1.3",
    "woocommerce/action-scheduler": "^3.8"
  },
  "extra": {
    "cleanup": {
      "dirs": ["bin", "tests", "docs"],
      "exclude": ["*.md", "*.yml", "*.xml", "tests", "docs"]
    }
  }
}
```

### NPM (`package.json`)

Current version and scripts are defined in the repo; for example:

```json
{
  "name": "performance-optimisation",
  "version": "1.8.0",
  "scripts": {
    "build": "wp-scripts build src/index.js src/lazyload.js src/main.js",
    "start": "wp-scripts start"
  }
}
```

See the root `package.json` for full `devDependencies` and `dependencies`.

---

## Changelog

For a full list of changes and version history, see [changelog.md](changelog.md).

---

## Contributing

Contributions, issues, and feature requests are welcome.
Please check the [issues page](https://github.com/nilesh32236/performance-optimisation/issues) if you would like to contribute.

---

## License

This project is licensed under the GPLv2 license. See the `LICENSE` file for more details.

---

## Available for Freelance Work

I am a Web Developer specializing in custom WordPress solutions, high-performance plugin development, and scalable backend architecture. If you are looking to build a custom web solution, optimize an existing high-traffic site, or need a dedicated technical partner for your next project, let us connect.

**Contact:** [nilesh32236@gmail.com](mailto:nilesh32236@gmail.com)

<br>

<div align="center">
<sub>Created by Nilesh Kanzariya. Built with a passion for high-performance web solutions.</sub>
</div>
