# Keyword Map — WordPress.org search intent → feature evidence

> Phase A (issue #1622). Documentation/metadata only; no runtime claims beyond code.
> Each row maps a directory search intent to the in-repo feature owner and the readme
> section that surfaces it. File paths are post-ARCH-013 (`includes/<Domain>/`).

| Search intent | Feature (evidence) | Readme section |
|---|---|---|
| page cache / cache plugin | Static HTML cache: `includes/Cache/class-cache.php`, served via `advanced-cache.php` drop-in (`includes/Cache/class-advanced-cache-handler.php`), smart purge on `save_post` | Description → Page Caching; FAQ “How do I speed up…” |
| core web vitals / pagespeed / LCP | Native lazy default + LCP guardrails and hero preload (`includes/Images/class-image-optimisation.php`, `includes/Images/class-lcp-preload.php`); RUM field LCP/INP/CLS (`includes/Insight/class-rum.php`); local telemetry scan (`includes/Insight/class-telemetry.php`); PageSpeed API integration (`includes/Insight/class-pagespeed.php`) | Short description; Description → Performance Monitor, Real-User Monitoring; FAQ “Does this plugin improve Core Web Vitals…” (targets metrics, results vary) |
| lazy load images / iframes / video | Native `loading="lazy"` default, opt-in IntersectionObserver + MutationObserver loader, SVG placeholders, LCP above-the-fold exclusion (`includes/Images/class-image-optimisation.php`) | Description → Image Optimization; FAQ “Does the plugin support lazy loading?” |
| webp / avif / next-gen images | GD/Imagick conversion to WebP/AVIF, AVIF-first `<picture>` output (`includes/Images/class-img-converter.php`, `includes/Images/class-image-optimisation.php`) | Short description; Description → Image Optimization; FAQ “How do I convert images…” |
| minify / defer / delay CSS JS | CSS/JS/HTML minify + combine, defer/delay strategies with exclusion lists (`includes/Core/class-main.php` facades → `includes/Minify/class-minify-policy.php`, `includes/Cache/class-cache.php` combine path) | Short description; Description → File Optimization; FAQ exclusions |
| database cleanup / revisions / transients | 9 cleanup types via `CLEANUP_METHOD_MAP`, batched `$wpdb` operations (`includes/Database/class-database-cleanup.php`), shared dispatch (`includes/Database/class-database-cleanup-runner.php`) | Description → Database Cleanup; FAQ “How do I clean up…” |
| redis / object cache | Standalone/Sentinel/Cluster + TLS (`includes/Cache/class-object-cache.php`), value policy (`includes/Cache/class-redis-config-policy.php`), drop-in `templates/object-cache.php` | Description → Redis Object Cache; FAQ shared hosting (Redis optional) |
| free performance plugin | 100% free, no premium/upsells (readme FAQ + plugin header); all tabs in one dashboard | Short description (“Free …”); FAQ “Is this plugin free?” |

## Tag rationale (exactly 5, WordPress.org-legal)

Chosen tags (`readme.txt:3`): `cache, performance, optimization, lazy-load, minify`.

- All lowercase, hyphenated multi-word form (`lazy-load`), no competitor or trademark names
  (`pagespeed`, `speed` removed — `pagespeed` evokes Google PageSpeed trademark; `speed` is
  redundant stuffing next to `performance`).
- Each tag maps to a row above: `cache` → page cache; `performance`/`optimization` → Core Web
  Vitals + general intent; `lazy-load` → lazy loading; `minify` → CSS/JS minification.
- Redis, database cleanup, WebP/AVIF remain fully described in the readme body (advanced and
  core sections) without spending scarce tag slots on them.

## Hierarchy rule

Core benefits (cache, vitals/RUM, images, file optimization, preload, database, Redis,
monitor) stay first in the Description; LiteSpeed/ESI/edge-purge/crawler/bfcache/llms.txt/
Abilities live under “Advanced capabilities” lower in the readme. No feature claim is made
that does not trace to a file in the table above.
