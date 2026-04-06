# 🛠️ Performance Optimisation: Comprehensive Technical Roadmap

**Internal Reference Only** | **Current Version:** 1.2.0 | **Last Updated:** 2026-04-06

This document serves as the master blueprint for the Performance Optimisation plugin's evolution. It details the technical architecture, implementation logic, and milestone objectives for the upcoming versions.

---

## 📈 Versioning & Release Standard
We use **Semantic Versioning (SemVer)**:
- **Major (X.0.0)**: Breaking changes, major UI/UX re-platforming (e.g., moving to a new CSS framework or API architecture).
- **Minor (1.X.0)**: Feature-rich releases (CDN, Redis, Local Fonts).
- **Patch (1.X.X)**: Maintenance, security hardening, and minor UI polishes.

---

## ✅ Version 1.2.0: The "Cache Core" Completion (COMPLETED / TESTING)
**Focus:** Enhancing the "Static HTML" engine and asset delivery speed.
**Timeline:** 3–4 Weeks

### 1. Server-Side Rules (.htaccess Automation)
- **Logic**: Use `insert_with_markers()` to append rules outside the `# BEGIN WordPress` block to prevent overwrites.
- **Rules (Gzip/Deflate)**: Implement `mod_deflate` for `text/html`, `text/css`, `application/javascript`, and `image/svg+xml`.
- **Rules (Browser Caching)**: Implement `mod_expires` with a standard `1 month` duration for images/CSS/JS and `0 seconds` for HTML.
- **Verification**: Check `Content-Encoding: gzip` and `Cache-Control` headers in the network tab.

### 2. CDN URL Rewriting
- **Trigger**: Hook into `template_redirect` after output buffering starts.
- **Regex Logic**: Target `src` and `href` attributes containing the site's local domain and `/wp-content/` or `/wp-includes/`.
- **Pattern**: `/(src|href)=["'](https?:\/\/[^"']+\/(wp-content|wp-includes)\/[^"']+)["']/i`.
- **Logic**: Replace the origin domain with the user-provided CNAME (e.g., `cdn.example.com`).

### 3. Smart Cache Purging (Granular Invalidation)
- **Problem**: Currently, `save_post` triggers a flush of the post cache. We need to extend this.
- **Requirement**: When a post is updated:
    1. Purge the specific post's HTML cache.
    2. Purge the Front Page (`index.html`).
    3. Purge the Blog Archive/Category archives associated with the post.
- **Implementation**: Update `class-cache.php::invalidate_dynamic_static_html()` to resolve these additional paths.

---

## 🎯 Version 1.3.0: Object Caching (Redis/Memcached)
**Focus:** Minimizing Database overhead for logged-in users and dynamic sites.
**Timeline:** 3–4 Weeks

### 1. Architecture: The `object-cache.php` Drop-in
- **Logic**: Provide a button to "Enable Object Cache" that copies a specialized drop-in file to `wp-content/object-cache.php`.
- **Logic**: Use the `WP_Object_Cache` global class to intercept `wp_cache_get` and `wp_cache_set`.

### 2. Provider Integration
- **Redis**: Use `PhpRedis` (preferred) or `Predis` library. Logic must handle persistent connections.
- **Memcached**: Use the `Memcached` PHP extension.
- **Test Connection**: Implement a REST API endpoint that attempts a `$redis->connect()` or `$memcached->getVersion()` and returns success/fail status to the React UI.

---

## 🎯 Version 1.4.0: Advanced Web Vitals (Technical SEO)
**Focus:** Eliminating render-blocking resources and domain lookups.
**Timeline:** 3–4 Weeks

### 1. Localize External Assets (Google Fonts/Analytics)
- **Google Fonts**: Scan HTML for `fonts.googleapis.com` links. Download the CSS and the font files (`.woff2`) to `wp-content/uploads/wppo-fonts/`. Update CSS paths to local URLs.
- **Analytics**: Download `gtag.js` or `analytics.js` locally and serve via a cron-updated local file to satisfy "Leverage browser caching" metrics.

### 2. Developer Tooling (WP-CLI)
- **Command**: `wp wppo clear --all` (Clear all page/object cache).
- **Command**: `wp wppo optimize --images` (Trigger background optimization of the media library).
- **Structure**: Create a `class-cli.php` that registers commands using `WP_CLI::add_command`.

---

## 🎯 Version 1.5.0: Enterprise & Ecosystem
**Focus:** Scalability and professional environment management.
**Timeline:** 3–4 Weeks

### 1. Cloudflare Dashboard Integration
- **Logic**: Allow users to enter their Cloudflare API Token and Zone ID.
- **Logic**: When the plugin cache is cleared, trigger a remote purge request to:
    `DELETE https://api.cloudflare.com/client/v4/zones/:zone_id/purge_cache`
- **Payload**: `{"purge_everything": true}` or targeted URLs based on Smart Purging rules.

### 2. Multisite Support (Network Mode)
- **Logic**: Support `Network Activate`.
- **Data**: Use `get_site_option()` to store global settings and `get_option()` for per-site overrides.
- **UI**: Add a "Network Settings" page for super-admins to enforce global performance rules across all child sites.

---

## ✅ Post-Development Checklist
- [ ] **PHP Compatibility**: Ensure compatibility with PHP 7.4 through 8.3.
- [ ] **Permissions Check**: Verify that the plugin warns the user if `.htaccess` or `wp-content/cache` is not writable.
- [ ] **Benchmarking**: Every minor release must show at least a **10% improvement** in Time to First Byte (TTFB) or Speed Index on a standard test site.
