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

**Safe defaults:** Page caching and native lazy loading are available on a fresh install, while aggressive options (defer/delay JavaScript, WooCommerce asset stripping, server rules) are **off** by default. LCP guardrails, WooCommerce safe mode, and bounded cache safeguards remain on.

---

## What's New in v2.4.0

- **Recursive responsibility architecture:** runtime code is organized by cache, settings, assets, CSS, images, edge delivery, insight, integrations, scheduler, support, and admin boundaries, with a generated source reference.
- **Safer defaults and rollouts:** native lazy loading, LCP guardrails, WooCommerce safe mode, staged sandbox settings, and explicit aggressive-transform opt-ins.
- **Reliability tooling:** `wp wppo verify` reports live cache, drop-in, Redis, LiteSpeed, settings-schema, cron, and uninstall-hygiene checks.
- **Operational visibility:** PageSpeed, first-party Web Vitals, guided next steps, autoloaded-options audit, edge/CDN purge coordination, and bounded crawler/preload status.
- **Compatibility:** PHP 8.2+, WordPress 6.2+, tested through WordPress 7.1, with guarded WordPress 6.9+ APIs and LiteSpeed coexistence modes.

See the full [changelog](readme.txt) and the maintained [site documentation source](docs/site/README.md).

---

## ⚡ Performance Showcase (Before vs After)

Illustrative results from a controlled test installation (Astra theme, 5 images, comments). **Your mileage varies** — actual numbers depend on your host, theme, plugins, and content.

| Metric | Before | After | Improvement |
| :--- | :--- | :--- | :--- |
| **Mobile PageSpeed** | 52 / 100 | **98 / 100** | **+88%** |
| **TTFB (Time to First Byte)** | 680 ms | **45 ms** | **-93%** |
| **LCP (Largest Contentful Paint)** | 4.1 s | **1.2 s** | **-71%** |
| **Total Page Size** | 3.2 MB | **820 KB** | **-74%** |

See the methodology and detailed desktop/mobile breakdown in [PERFORMANCE.md](PERFORMANCE.md).

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
- **Smart Lazy Loading:** Native browser lazy loading is preferred. The opt-in observer can defer offscreen images, iframes, and videos with placeholders and observes dynamically injected content.
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

Performance Optimisation includes compatibility safeguards and guarded behavior for common WordPress environments and integrations. Test cache, minification, defer/delay, image, and CDN behavior on staging before production; safeguards are not a blanket guarantee for every theme or plugin combination:
- **Themes:** Astra, GeneratePress, Kadence, OceanWP, Blocksy, and Twenty Twenty-Four are supported starting points.
- **Page Builders:** Elementor, Divi, Beaver Builder, and WPBakery receive exclusion, purge, and builder-update safeguards.
- **E-Commerce:** WooCommerce safe mode excludes dynamic cart, checkout, account, Store API, AJAX, and faceted routes.
- **SEO Plugins:** Yoast SEO, Rank Math, All in One SEO, and SEOPress are treated as integration contexts; feeds and REST/sitemap routes remain dynamic.

---

## Dependencies & Tech Stack

This plugin leverages modern development practices, utilizing Composer for PHP dependencies and NPM/Webpack for React-based admin interfaces.

- **[voku/html-min](https://github.com/voku/HtmlMin):** PHP library for HTML minification.
- **[matthiasmullie/minify](https://github.com/matthiasmullie/minify):** PHP library for JavaScript and CSS minification.
- **[@wordpress/scripts](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/):** Build tools for modern WordPress Block Editor and React development.
- **UI Components:** Font Awesome Free Solid Icons and React FontAwesome.

---

## Documentation

The maintained, code-accurate documentation is published at [nileshportfolio.duckdns.org/docs/](https://nileshportfolio.duckdns.org/docs/):

- [Performance Optimisation overview](https://nileshportfolio.duckdns.org/docs/performance-optimisation/)
- [Installation and first run](https://nileshportfolio.duckdns.org/docs/performance-optimisation/installation/)
- [Features Guide](https://nileshportfolio.duckdns.org/docs/performance-optimisation/features/)
- [Configuration and developer reference](https://nileshportfolio.duckdns.org/docs/performance-optimisation/configuration/)
- [Troubleshooting](https://nileshportfolio.duckdns.org/docs/performance-optimisation/troubleshooting/)
- [Support and safe recovery](https://nileshportfolio.duckdns.org/docs/performance-optimisation/support/)
- [Compatibility](https://nileshportfolio.duckdns.org/docs/performance-optimisation/compatibility/)
- [Generated code reference](https://nileshportfolio.duckdns.org/docs/performance-optimisation/reference/)

The source fragments live in [`docs/site/`](docs/site/), and the API reference is regenerated from the current recursive source tree. Documentation examples are operational guidance, not guaranteed performance scores.

---

## Installation & Setup

### For End Users

1. Download the latest ZIP from the [WordPress.org listing](https://wordpress.org/plugins/performance-optimisation/) or the repository's Releases page.
2. In WordPress, open **Plugins → Add New → Upload Plugin**, select the ZIP, install it, and activate it.
3. Follow the [online installation guide](https://nileshportfolio.duckdns.org/docs/performance-optimisation/installation/) and enable one optional optimization at a time.

A Composer/Node build is only needed when developing from source.

### For Developers

1.  Follow the installation steps above.
2.  To start the Webpack development environment and watch for changes:
    ```bash
    npm run start
    ```

---

## Support and safe recovery

If a recent optimization breaks the site, disable the narrowest advanced feature, save, clear the affected page cache, and test a logged-out page. The [support guide](https://nileshportfolio.duckdns.org/docs/performance-optimisation/support/) covers the existing preset restore point, feature controls, `wp wppo verify`, and redacted diagnostic evidence.

Before reporting a bug, record the URL, exact steps, WordPress/PHP/server versions, cache owner, and feature you changed. Include redacted `wp wppo system-info` output. Never post passwords, API keys, cookies, bearer tokens, session IDs, private customer data, or database exports.

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
    "php": ">=8.2",
    "voku/html-min": "^5.0",
    "matthiasmullie/minify": "^1.3",
    "woocommerce/action-scheduler": "^4.1",
    "symfony/css-selector": "^7.4"
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
  "version": "2.4.0",
  "scripts": {
    "build": "wp-scripts build src/index.js src/lazyload.js src/main.js src/rum.js src/esi.js",
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
