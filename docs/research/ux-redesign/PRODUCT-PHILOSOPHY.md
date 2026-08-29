# PRODUCT-PHILOSOPHY.md — Permanent Principles

**Date:** 2026-08-29 | **For:** Performance Optimisation 1.9.0 → 2.0 | **Guides all future development**

## Who the Product Is For
1. **Normal Site Owner** (Persona 1) — “Make my site faster” without understanding caching. 60-70% of installs. Needs outcome, not implementation.
2. **Freelancer/Agency** (Persona 2) — Manages 5-50 sites, wants quick safe setup + diagnose. Reuses export/import `PluginSetting.js:301-318`, needs clear recommendations.
3. **Developer** (Persona 3) — Fine-grained control, per-page Asset Manager `class-metabox.php:453`, 30+ hooks `docs/hooks.md`, WP-CLI `class-wppo-cli-command.php`.
4. **Performance Specialist** (Persona 4) — Deep diagnostics, PSI `PageSpeedPanel.js:479`, RUM `WebVitalsRum.js:177`, Autoload `AutoloadedOptions.js:133`, SystemInfo `SystemInfo.js:353`.

All four must use same install without forcing same complexity — **tiered disclosure, not separate products**.

## What Problem It Solves
- **First load fast:** Static HTML `class-cache.php:1572-1838` + Gzip `class-htaccess-handler.php` + CDN `class-cache.php:1318` → TTFB <200ms.
- **Assets lean:** Minify/combine/defer/delay `class-main.php:660-755`, Critical/Used CSS `class-critical-css.php` / `class-used-css.php` → no render-blocking.
- **Images modern:** WebP/AVIF `class-img-converter.php` + lazy `src/lazyload.js` + LCP preload → largest content fast.
- **DB lean:** 9 cleanup types `class-database-cleanup.php:54-127` → query overhead Healthy.
- **Data warm:** Preload `class-cron.php` + speculation `class-main.php:655` → next page instant.

## What Users Should Understand (Outcome)
- Benefit (“pages load faster for returning visitors”), Risk (“may cause layout flash on some themes — preview shows”), Recommendation (“Recommended for most sites. Leave off if cart breaks.”)
- Health status: **Good / Needs attention / Needs review** (not raw 2.5s/500ms `PerformanceAudit.js:121-129` until drilled).
- Next action: 1-3 suggestions merged `Dashboard.js:78-91`, not 40 toggles.

## What Users Should NOT Need to Understand
Hooks, cache headers, object-cache topologies, CSS/JS processing, RUM token/rate-limit `class-rum.php`, CDN rewrite `WP_HTML_Tag_Processor`, invalidation strategies, `DOM_SIZE` `PerformanceAudit.js:88`, `styles_inline_size_limit` `class-cache.php:748`, `wppo_*` filters — hidden behind Advanced/Diagnostics.

## Default Experience
- On install: **Recommended** one-click safe set (`enableCache true`, `minifyCSS/JS true` with `exclude_js jquery` `class-main.php:46`, `lazyLoadNative true` `class-main.php:224`, `removeHTMLComments true:205`, lifespan 24h) — not all-false `class-main.php:170-214` today.
- User sees **Health header** (Speed/Stability/Efficiency 0-100 rings + badges) + 3 actions + Welcome checklist `WelcomePanel.js:9-56` upgraded to wizard — not 7 tabs.
- Zero required decisions before value; Apply → Verify re-scan shows win.

## Advanced Experience
- Clearly separated: **Advanced** accordion/search inside each pillar, not flat 7 tabs `App.js:96-135`.
- Exposes: Sentinel/Cluster/TLS `ObjectCache.js:488-853`, delay strategies `FileOptimization.js:936-1123`, Used CSS safelist `496-538`, per-page Asset Manager metabox `Asset_Manager:245`, raw Server Rules `1631-1649`, 30+ hooks documented `docs/hooks.md:493`.
- Discoverable via URL `?tab=speed&advanced=1` + global search, not buried.

## Developer Experience
- Keep powerful: WP-CLI 7 verbs `--dry-run/--yes` `class-wppo-cli-command.php`, REST 28 endpoints `class-rest.php`, filters `wppo_should_cache_request:1524` `wppo_invalidation_urls:1920`, SystemInfo env dump, cron `wppo_*` 15 events, import/export `Rest.php:734-800`.
- Docs split: User (readme.txt Description) vs Developer (docs/hooks.md, WP-CLI-CURRENT.md).

## Safety Philosophy — “I can safely try this.”
- Safe defaults all `false` `class-main.php:170-214` remain; Recommended is opt-in with **preview + confirm** `ConfirmDialog.js:1-182`, not auto.
- Warnings explain consequence not implementation: “Help browsers load returning visitors faster — may cause flash on some themes” vs “Enable browser cache headers”.
- Validation before apply, conflict banner “Another plugin handles this — leave off” vs “Option X conflicts Y”, easy rollback via snapshot + `Clear All Cache` `class-rest.php:371`, `Reset to Recommended` `class-main.php:857 migrate_block_assets`, emergency `?wppo_safe=1` kill-switch (new) checked in `setup_hooks:489`.
- Danger Zone `DatabaseCleanup.js:468-487` `Optimize Everything Now` needs `ConfirmDialog` + row sample, not just red button.

## Automation Philosophy
- Detect first: server `Server_Rules::get_server_type:191`, LS `LiteSpeed_Integration:800`, existing optimizers via `is_plugin_active` (today only LS `Dashboard.js:686-693`), Redis `Object_Cache:1047 salted`, image formats GD/Imagick `Img_Converter:84`, WP 7.1 wasm-vips `ImageOptimization.js:506`, multisite `Util::transient_key:781`.
- **AUTOMATE** if safe: logged-in role hash `class-cache.php:297`, `blockAssetsOnDemand` on 6.9+ `class-main.php:185`, native lazy `ImageOptimization.js:42`, sitemap discovery `Cron::get_sitemap_urls:500 cap`.
- **RECOMMEND** if needs review: Minify, defer, critical CSS — show benefit/risk/badge.
- Leave **MANUAL** if user intent: CDN hostname `FileOptimization.js:1684`, DB schedule `DatabaseCleanup.js:197`.

## Terminology Philosophy
- Outcome over implementation: “Help browsers load returning visitors faster” not “Enable Expires headers” `class-htaccess-handler.php`.
- Keep established terms for advanced users (Object Cache, Critical CSS) but simplify tooltip: “Speed up repeated data requests (Object Cache)” `TERMINOLOGY.md`.
- Avoid raw metrics until drill-down; badge first `StatusBadge.js:1-56`.

## Configuration Philosophy
- Don’t make users configure what software can determine — see `AUTOMATION-CANDIDATES.md` 55 rows.
- Group around **user intent** (Make pages faster / Improve images / Clean up) not implementation (Cache/CSS/JS/HTTP/DB).

## Error Philosophy
- Translate technical failure → useful action: “Purge failed — FTP permission 755 needed `class-main.php:989` — try Clear All again” not “`WP_Filesystem error`”.
- Every screen defines Loading/Empty/Success/Warning/Error/Disabled/Unsupported/Conflict/Partial `SAFETY-RECOVERY.md`.

## Performance Philosophy
- Less configuration, not less capability — proposal adds health header (3 scores replacing 4 stat cards `Dashboard.js:823-971`), not more chrome.
- Calm/Clear/Trustworthy/Fast/WordPress-native `src/css/abstracts/_variables.scss:1-85` vars, no marketing dashboard.

## Accessibility Philosophy
- WCAG 2.2 AA: `src/css/base/_base.scss:108-127` `:focus-visible` 2px, `SwitchField.js:50 ToggleControl`, `Tooltip.js:30 aria-describedby`, `NoticeBanner.js:role=alert`, keyboard roving `App.js:251-274`, focus trap `App.js:215-260`/`ConfirmDialog.js:1-182` preserved through redesign.

