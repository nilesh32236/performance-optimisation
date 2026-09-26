# WordPress.org Search Intent Map

**Campaign:** Phase A — WordPress.org searchability
**Repository:** `nilesh32236/performance-optimisation`
**Research date:** 2026-09-25

## Research basis

- Current listing: [WordPress.org plugin page](https://wordpress.org/plugins/performance-optimisation/) and its [official markdown rendering](https://wordpress.org/plugins/performance-optimisation/?output_format=md). The page currently identifies the plugin as **Performance Optimisation**, version 2.4.0, and the repository/readme tag set as `cache`, `performance`, `speed`, `pagespeed`, `minify`.
- The current official WordPress.org plugin API entry for the slug reports the same five tags: [plugin information API](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=performance-optimisation).
- Current category/tag discovery pages show active terminology including [performance](https://wordpress.org/plugins/tags/performance/), [cache](https://wordpress.org/plugins/tags/cache/), [core-web-vitals](https://wordpress.org/plugins/tags/core-web-vitals/), [image compression](https://wordpress.org/plugins/tags/image-compression/), [WebP](https://wordpress.org/plugins/tags/webp/), and [AVIF](https://wordpress.org/plugins/tags/avif/).
- Comparison metadata was checked through the official plugin API for [LiteSpeed Cache](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=litespeed-cache), [WP Super Cache](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=wp-super-cache), [W3 Total Cache](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=w3-total-cache), and [Autoptimize](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=autoptimize). This is terminology comparison only; no ranking or popularity claim is made.

## Intent map

| Phrase | User intent | Existing feature that satisfies it | Best WordPress.org location | Reason it is relevant | Wording change |
|---|---|---|---|---|---|
| WordPress performance | Improve site speed and responsiveness | Performance monitor, page cache, asset and image optimization | Title, short description, Description, Core Performance Features | Primary category intent; current description should lead with it | Yes |
| WordPress speed | Make pages load faster | Static page cache, image conversion, minification, lazy loading | Short description, Why choose, Page Caching | Common plain-language intent | Yes |
| WordPress optimization | Improve multiple performance layers | Cache, CSS/JS, images, database, Redis | Description and Core Performance Features | Broad umbrella term with verified feature support | Yes |
| WordPress performance plugin | Find a consolidated performance tool | One plugin combines core speed features | Short description, Description | Closest product-category intent | Yes |
| WordPress speed optimization | Apply practical speed improvements | Caching, minification, defer/delay, WebP/AVIF, preload | Why choose and Core Performance Features | Natural phrase; keep it in prose, not repeated mechanically | Yes |
| WordPress cache / page cache | Serve static pages efficiently | Static HTML cache, Gzip, smart invalidation | Tags, short description, Page Caching | Direct feature and category intent | Yes |
| WordPress caching | Cache pages and related delivery assets | Page cache, preload, Redis object cache | Tag, Database & Object Cache | General cache intent broader than page cache | Yes |
| Static page cache | Serve generated HTML from disk | `Cache` static HTML generation and serving | Page Caching | Specific verified feature terminology | Yes |
| Core Web Vitals WordPress | Improve LCP, INP, and CLS | PageSpeed monitor and first-party RUM | Tag, short description, Core Web Vitals & Monitoring | Current WordPress.org search category and direct feature | Yes |
| WordPress PageSpeed | Scan and diagnose performance | Google PageSpeed Insights integration | Tag, Core Web Vitals & Monitoring, FAQ | Existing feature and current tag language | Yes |
| LCP WordPress | Improve Largest Contentful Paint | Hero preload, fetchpriority, image optimization, RUM | Image Optimization and Core Web Vitals | Direct metric intent | Yes |
| INP WordPress | Improve interaction responsiveness | Delay-JS presets, asset policy, RUM | Core Web Vitals & Monitoring | Existing metric monitoring and opt-in delay behavior | Yes |
| CLS WordPress | Reduce layout shift | RUM measurement, image loading and asset controls | Core Web Vitals & Monitoring | Existing measurement, not a guaranteed score outcome | Yes |
| CSS minify WordPress | Reduce CSS delivery cost | CSS/HTML minification and combine policy | Tag candidates and CSS & JavaScript Optimization | Verified frontend feature; “minify” remains useful in FAQ/feature prose | Yes |
| JavaScript optimization WordPress | Control script loading and size | JS minification, defer, delay, bloat removal | Short description, CSS & JavaScript Optimization | Common frontend intent | Yes |
| Defer JavaScript WordPress | Reduce render-blocking scripts | Opt-in defer JS with exclusions | CSS & JavaScript Optimization, FAQ | Specific verified feature | Yes |
| Delay JavaScript WordPress | Load selected scripts later | Opt-in delay JS and presets | CSS & JavaScript Optimization, FAQ | Specific verified feature; preserve opt-in safety wording | Yes |
| Lazy load WordPress | Defer off-screen media | Native and JavaScript lazy loading for images, iframes, video | Image Optimization, FAQ | Existing feature and common search phrase | Yes |
| Image optimization WordPress | Reduce image payload | WebP/AVIF conversion, lazy loading, hero preload | Tag, short description, Image Optimization | Direct category intent | Yes |
| WebP WordPress | Convert images to WebP | WebP conversion and serving | Tag, Image Optimization, FAQ | Existing format support and active WP.org tag | Yes |
| AVIF WordPress | Convert images to AVIF | AVIF conversion and serving | Image Optimization, FAQ | Existing format support and active WP.org tag | Yes |
| Redis object cache WordPress | Improve persistent object-cache performance | Redis standalone, Sentinel, Cluster, TLS | Database & Object Cache | Direct infrastructure intent; configuration prerequisite must be clear | Yes |
| LiteSpeed WordPress | Coexist safely with LiteSpeed/OLS | Auto/WPPO/LiteSpeed/Standalone modes and header protocol | Compatibility, Advanced Features | Direct server compatibility intent; avoid implying LSCache replacement | Yes |
| CDN WordPress | Purge and rewrite edge delivery | CDN URL rewriting, Cloudflare/Bunny/Varnish purge | Advanced Features, External Services | Existing functionality; configuration and external-service disclosure required | Yes |
| WooCommerce performance | Keep commerce pages correct and fast | Dynamic route detection, cache exclusions, self-test, optional asset rules | Compatibility, FAQ | Direct integration intent; no blanket guarantee | Yes |
| Database optimization WordPress | Remove stale data safely | Database cleanup and schedules | Database & Object Cache | Existing feature and natural user term | Yes |

## Mapping decisions

- **Included:** Terms map to a named feature, a documented compatibility safeguard, or a required hosting/configuration concept. The map does not use competitor names or unsupported terms.
- **Not used as primary copy:** “SEO ranking”, guaranteed score improvements, “all-in-one” as the opening promise, and unsupported claims of universal theme/plugin compatibility. These either overpromise outcomes or do not describe a direct feature.
- **Advanced terminology:** AI Adaptive, RUM, Edge Cache, ESI, Abilities API, llms.txt, bfcache, and crawler controls remain documented, but are moved below the first-screen core value proposition.
