# TERMINOLOGY.md — Current → Normal → Advanced

| Current | File:Line | Problem | Normal wording | Advanced wording | Tooltip/help | Reason |
|---------|-----------|---------|----------------|------------------|--------------|--------|
| Enable browser cache headers | `class-htaccess-handler.php` `FileOptimization:1521` | Implementation not benefit | Help browsers load returning visitors faster | Browser cache headers (Expires/Gzip) | "Returning visitors reload from cache — saves ~500ms. Needs Apache/.htaccess or Nginx config." | Outcome over implementation |
| Minify CSS/JS/HTML | `FileOptimization:333,737,658` | Whitespace jargon | Make files smaller | Minify CSS/JS/HTML | "Remove whitespace/comments — saves 10-20% without changing appearance. Exclude jQuery if breaks." | Keep term but explain benefit |
| Combine CSS | `:358` | Single file vs inline-budget hidden | Combine styles into one file (advanced) | Combine CSS + preload hint budget `class-cache.php:748` | "Fewer requests but may cause flash (FOUC) on some themes. Use Exclude if broken." | Risk disclosed |
| Defer JavaScript | `:737` | Load later vague | Load scripts after page shown | Defer JS (native `strategy:defer` WP6.3) | "Scripts load after content — faster paint, safe for most sites." | Benefit |
| Delay JavaScript | `:901` `936-1123` | Same as defer confused | Load scripts only after interaction (advanced) | Delay JS (rewrite src→wppo-src) | "Delays until scroll/click/idle 3s — saves CPU but may break checkout. Test." | Distinguish |
| Critical CSS | `CriticalCssPanel` | Above-fold jargon | Load visible styles first | Critical CSS (above-the-fold inline) | "Visible styles inline — faster LCP. Regenerate after theme change." | LCP benefit |
| Remove Unused CSS | `:496` | PurgeCSS 30-80% | Remove styles not used on page (advanced) | Remove Unused CSS (PurgeCSS) Safelist | "Removes unused selectors — needs Safelist review." | Advanced |
| Host Google Fonts Locally | `FileOptimization:Assets` | Intercept buffer `font-display:swap` | Load Google Fonts from your site | Host Google Fonts Locally + clear cache on toggle | "Avoid external DNS + GDPR. Fonts load locally." | GDPR benefit |
| Object Cache | `ObjectCache.js:37` | Repeated queries jargon | Speed up repeated data requests | Object Cache (Redis Standalone/Sentinel/Cluster) | "Stores frequent database results in Redis — needs server Redis." | Outcome + technical |
| Page Cache + Lifespan Never→1 week | `Dashboard:974` | Static HTML drop-in | Store pages for fast loading + How long | Page Cache (Static HTML `advanced-cache.php` + Gzip) | "Pages saved as HTML — bypass WordPress. 24h recommended." | Lifespan as duration |
| Preload Cache / Warm-up | `PreloadSettings.js:22` | Cold cache jargon | Warm up cache in background | Preload Cache (`wppo_page_cron_hook 5h` 200/batch) | "Pre-build pages so first visitor not slow." | Warm-up friendlier |
| Speculative Loading prerender/moderate | `Preload:433` `class-main.php:655` | Next-page inflate | Predict next page (advanced) | Speculation Rules API prerender moderate `*` | "Preloads next page on hover — may inflate analytics." | Risk |
| Lazy Load + Native | `ImageOptimization.js:42` | Below-fold vs `loading=lazy` | Load images as you scroll | Lazy Load native `decoding=async` vs IntersectionObserver `src/lazyload.js` | "Off-screen images wait — hero excluded first 3." | Hero rule |
| WebP/AVIF | `ImageOptimization:506` | Formats | Modern image formats 25-50% smaller | WebP/AVIF via GD/Imagick Action Scheduler 50 | "JPEG/PNG → WebP/AVIF automatically." | Savings |
| Autoloaded Options | `AutoloadedOptions.js:133` | LENGTH | Bloated options (diagnostic) | Autoloaded Options top20 LENGTH | "Every page loads these — find bloated plugins." | Diagnostic term kept advanced |
| Database Overhead Healthy/Medium/High | `Dashboard:675` | Items count | Database Health | Overhead Items `wppo_database_cleanup_cron` | "Revisions/transients slow queries. Clean weekly." | Health not overhead |
| CDN Hostname | `FileOptimization:1684` | Origin vs CDN `WP_HTML_Tag_Processor` | Content delivery (CDN) | CDN Hostname rewrite`cdnURL` | "Serve wp-content from CDN — origin stays." | CDN friendlier |
| Server Rules .htaccess | `FileOptimization:1521` |FTP 755 `class-main.php:989` | Browser caching (server) | Server Rules .htaccess Gzip+Expires `insert_with_markers` | "Apache saves Expires headers. Nginx needs manual copy `1631`." | Server-agnostic |
| Heartbeat Control | `FileOptimization:Core` | Admin heartbeat flood | Admin refresh frequency | Heartbeat Control default/60s | "Admin chat polling — reduce if slow." | Frequency |
| Logged-in Cache Role Hash | `Dashboard` `class-cache.php:297` | Hash variant files | Cache for logged-in users | Logged-in Cache `wppo_role_hash` variant files | "Members see own cache — not shared. Choose roles." | Leak risk |

Keep established technical for advanced (Object Cache, Critical CSS) simplified wording appears in normal tooltip.
