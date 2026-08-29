# ONBOARDING.md — Install → Verify Wizard

**Current:** `WelcomePanel.js:9-56` 3 steps cache/minify/lazy at top Dashboard `Dashboard:800` auto-dismiss `WelcomePanel:78` after any save — misses detection, server rules, CDN, recommended bundle, progress, no wizard route.

## Ideal Flow (6 steps, not 20)
```
1 Welcome — "Make your site faster in 2 minutes" + Detect summary (WP 7.1 PHP 8.3.33 LiteSpeed HIT Redis PONG boltfolio no conflict)
2 Environment — Server: LiteSpeed `x-litespeed-cache: hit` + Apache detection `Server_Rules:191`, CDN none, Object Cache PONG 1.36M `ObjectCache:922`, GD/Imagick available
3 Analyze — Auto Telemetry `PerformanceAudit.js:292` homeUrl `wppoSettings.performance_audit.homeUrl` cached 1h skeleton + PSI if `pagespeed_api_key` `PluginSetting:108` else skip
4 Recommendations — 3 tiers: Safe (Apply Recommended: cache ON `Main:170`, minify CSS `B1` ON, minify JS `B9` ON, defer `B10` ON, lazy native D1 ON, lifespan 24h) | Review (Critical CSS B5, Host Fonts B6, WebP D6) | Advanced (Combine B2, Delay B11)
5 Review — List benefit+ risk+ recommendation per toggle: "Make JS smaller — faster parse — safe — Recommended" vs "Delay until interaction — saves CPU but breaks checkout — test"
6 Apply → Verify — Single `apiCall update_settings` bundle payload pattern `WelcomePanel:33-40` with snapshot + `Clear All Cache` `Rest.php:371` + re-scan delta + Done with "Re-open wizard Manage→Tools"
```

## Wizard Design
- **Entry:** On activation `Activate::maybe_run_upgrades` `class-activate.php:354` show admin notice "Setup Performance Optimisation →" + WelcomePanel CTA "Open Setup Wizard" modal route `?wppo_wizard=1`.
- **Steps:** 6 dots progress, Next/Back/Skip to Dashboard, "Apply Recommended" primary green, "Custom" secondary, Dismiss persists `wppoSettings.show_welcome` `WelcomePanel:60` + `dismiss_welcome` `:113` but not auto after partial save.
- **Safe set payload:** `{cache_settings:{enableCache:true,loggedInCacheRoles:[]}, file_optimisation:{minifyJS:true,minifyCSS:true,deferJS:true,hostGoogleFontsLocally:false, ...safe 10}, image_optimisation:{lazyLoadImages:false but lazyLoadNative:true, convertImg:true}}` — reuses `WelcomePanel:33-40` 3-minify+lazy bundle.
- **Detection:** Before step3 call `apiCall system_info` `SystemInfo.js:353` + `serverRules` `FileOptimization:1521` to branch Apache/LS vs Nginx code block.

## One-Click Recommended
- As `docs/competitive-audit-2026.md` NitroPack cloud one-click `competitive-audit-2026.md:62` vs FlyingPress Recommended vs Advanced `32-63` — we offer "Apply Recommended" (10 safe toggles) + "Advanced" disclose — not 40 Required.
- **Safe includes:** `enableCache true`, `minifyCSS true`, `minifyJS true`, `minifyHTML true`, `deferJS true`, `lazyLoadNative true`, `removeHTMLComments true:205`, `preloadCache true:220`, `convertImg true` — all low risk `FEATURE-TREATMENT` RECOMMEND.
- **Excluded:** `combineCSS false:197`, `delayJS false:196`, `removeUnusedCSS false:179`, `criticalCSS false:180`, `removeWooCSSJS false`, `enableServerRules false` (needs FTP).

## Why Not 20 Steps
- `FileOptimization.js:40-87` 32 defaults + `Preload 623` + `Image 954` + `Object 922` + `Database 644` = >30 decisions before value — wizard limits to 6 with progress bar, Advanced hidden behind "Show advanced (9 more)".

## Verification
- After Apply, `PerformanceAudit.js:292` re-scan + `PageSpeedPanel:479` PSI poll Action Scheduler `wppo_pagespeed_scan` + `WebVitalsTrends:229` delta + `RecentActivityCard:RecentActivityCard.js` log entry.

## Recovery
- Snapshot `wppo_settings` before Apply stored as `wppo_settings_snapshot_{time}` transient + Tools→Manage "Undo last wizard" 10min, plus `?wppo_safe=1` kill-switch.

