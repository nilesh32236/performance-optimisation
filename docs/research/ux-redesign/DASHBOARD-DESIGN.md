# DASHBOARD-DESIGN.md — Health Header Philosophy

**Current:** `Dashboard.js:1329` mixes 4 stat cards `823-971` Cache Size/Optimized Files/DB Overhead/Images + 8+ diagnostics `PerformanceAudit 841 + PageSpeed 479 + WebVitalsTrends 229 + WebVitalsRum 177 + Suggestions 270 + SystemInfo 353 + ImageOptimizationCard 208 + Autoload 133 + AiPanel 291 + Edge 261 + Llms 152` = ~2800 lines >4000px + 3 Save buttons — violates §11 "Not how many optimizations can I configure".

## Ideal First Screen Answers
- How is my site doing? → Health rings Speed/Stability/Efficiency Good/Needs/Review
- What can I improve? → 1-3 suggestions merged `Dashboard.js:78-91` by metric
- What should I do next? → Primary CTA Apply Recommended + secondary Advanced
- Is anything broken? → Warnings banner (LS `Dashboard:686-693`, conflict, failed queue 681) + Recent Activity

## Health Header (replaces 4 stat cards)
- **Speed** = LCP/TTFB + PSI mobile score `PerformanceAudit.js:121-129 numericStatus 200/500, 2.5/4` + `PageSpeedPanel:479` score → 0-100 ring `StatusBadge.js:1-56` semantic `--wppo-success #059669` `_variables.scss:34-46`
- **Stability** = CLS/INP + DOM size `dom_size:88` → ring
- **Efficiency** = Page size 500/1000 + unused CSS % `uses_modern_image_formats:582` + compression `gzip_brotli_compression:553` → ring
- Each ring badge Good/Needs/Poor via existing thresholds, number in tooltip not dominant — fixes raw TTFB/LCP overwhelm `Agent H`.
- Below header: stat strip simplified to Cache Size `wppo_cache_size` + Purge All `class-rest.php:371` + DB badge `675` + single "Optimized" % (merge Files+Images).

## Actions & Summary
- **Recommended actions** list: 3 cards max e.g., "Defer JavaScript — load after page — saves 1s — Recommended → Apply" with benefit/risk/badge (per Principle 4).
- **Optimization summary:** Applied toggles count vs Recommended total + "Advanced 9 hidden".
- **Cache status:** Lifespan `Dashboard:993-1028` select 0-168h promoted to header + Clear This Page `src/main.js`.
- **Health/status:** System Info `SystemInfo:353` collapsed to "WordPress 7.1 PHP 8.3.33 LiteSpeed → Details".
- **Recent activity:** `RecentActivityCard.js` 3 rows + "View all Manage→Activity Log".

## Avoid Unnecessary Dashboard
- No giant graphs, no marketing UI — calm/WordPress-native `src/css/abstracts/_variables.scss:1-85`.
- Sparklines `WebVitalsTrends:229` under Diagnostics drawer, not header.
- No marketing-style 9 cards `competitive-audit-2026.md:79` "don't fragment" → single Intelligence panel.

## Diagnostics Drawer
- "Show details" collapsible with full `PerformanceAudit:506-810` 16-row table + Asset Breakdown/Environment devMode `:288-290` behind `?healthDetails=1`, PageSpeed gauges, Autoload `133`, SystemInfo on demand.

## Quick Actions
- Header buttons: Purge All `Dashboard:774`, Run Scan `PerformanceAudit:292`, Apply Recommended — 3 max.

