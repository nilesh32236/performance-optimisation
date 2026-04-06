<div align="center">

# Performance Optimisation for WordPress

**A comprehensive WordPress plugin designed to optimize website performance by managing cache, minifying assets, and improving delivery with advanced routing and modern image formats.**

[![WordPress Version](https://img.shields.io/badge/WordPress-5.5+-blue.svg?style=flat-square&logo=wordpress)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-7.4+-777BB4.svg?style=flat-square&logo=php)](https://php.net/)
[![License: GPL v2](https://img.shields.io/badge/License-GPLv2-success.svg?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)

</div>

---

## About The Project

Website speed is critical for SEO, user retention, and conversion rates. The Performance Optimisation plugin acts as a centralized engine to fine-tune how a WordPress site loads. Instead of relying on multiple fragmented plugins, this solution provides a singular dashboard to manage caching, minify JavaScript/CSS/HTML, convert modern image formats, and intelligently preload critical resources.

Whether aiming for a perfect PageSpeed score or a seamless user experience, this plugin provides granular control over front-end delivery and backend asset management.

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
- **E-Commerce Optimization:** Automatically remove unnecessary WooCommerce CSS and JS from non-relevant pages to improve load times.

### Advanced Preloading Settings

- **Cache Generation:** Enable cache preloading to proactively generate static HTML and GZIP files.
- **Network Routing:** Add preconnect origins and prefetch DNS domains for faster third-party resource loading.
- **Resource Preloading:** Prioritize the loading of fonts, critical CSS, and specific images.
- **Dynamic Feature Images:** Preload feature images for specific post types with configurable exclusions.

### Image Optimization Settings

- **Next-Gen Formats:** Automatically convert images to highly compressed WebP or AVIF formats.
- **Smart Lazy Loading:** Defer offscreen images utilizing lightweight SVG placeholders for smoother rendering.
- **Exclusion Rules:** Limit preloaded image sizes and exclude specific images from lazy loading rules.

### Administrative Tools

- **Portability:** Import and export plugin settings with a single click for rapid deployment across multiple client sites.

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
```

### NPM (`package.json`)

```json
{
  "name": "performance-optimisation",
  "version": "1.0.0",
  "description": "Performance optimisation plugin for WordPress",
  "main": "./src/index.js",
  "scripts": {
    "build": "wp-scripts build",
    "start": "wp-scripts start"
  },
  "author": "Nilesh Kanzariya <nilesh.kanzariya912@gmail.com>",
  "license": "GPL-2.0-or-later",
  "devDependencies": {
    "@wordpress/scripts": "^27.9.0"
  },
  "dependencies": {
    "@fortawesome/free-solid-svg-icons": "^6.7.1",
    "@fortawesome/react-fontawesome": "^0.2.2"
  }
}
```

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
