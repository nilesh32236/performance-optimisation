# MIGRATION-PLAN.md — Settings → New IA Without Breaking

**DB:** `wppo_settings` single option `class-main.php:266` `Util::get_default_settings():81` 14 tabs 55 keys `ALLOWED_SETTINGS_KEYS:43` — **No DB migration** needed; grouping is UI only. On read `Util::get_settings:92` merges defaults, so missing `od_integration/bfcache/perf_translations` `Agent N` 11/14 backfills automatically. Stored `11→14` no write until user saves — safe.

| Old setting group | New UI pillar | Mapping | DB change | Old URL/route | Redirect |
|-------------------|---------------|---------|-----------|---------------|----------|
| `dashboard` `cache_settings` `A7/A8` | Overview Speed | `enableCache`+`lifespan`→ Overview header `Dashboard:974` `loggedInCacheRoles`→ Speed→Advanced logged-in `A8` | None | `#dashboard` `App:76` `activeTab dashboard` | `useEffect` `dashboard→overview` |
| `file_optimisation` 43 keys `Util:43` Assets/Scripts etc `FileOptimization:40-87` | Speed pillars CSS/JS/HTML/Server/CDN | `minifyCSS/B1`→Speed→CSS, `minifyJS B9/defer B10/delay B11`→Speed→JS, `minifyHTML B7/inline B8`→Speed→HTML, `cdnURL B15`→Speed→Network, `enableServerRules B14`→Advanced→Server `1521`, bloat 11 `B16`→Advanced→WordPress | None | `#fileOptimization` + `subTabs assets/scripts...:224` | `?tab=speed&section=css|js|html|server|network` via `App.js` router |
| `preload_settings` 15 keys `C1-6` | Speed→Preload+Connections | `enablePreloadCache C1`→Speed→Preload, `preconnect C3/dns C4`→Speed→Connections advanced, `preloadFonts/Css C5` keep, `speculation C6`→Speed→Preload advanced `433` | None | `#preload` `PreloadSettings.js:623` | `?tab=speed&section=preload` + `&advanced=1` for C3/C4/C6 |
| `image_optimisation` 15 keys `D1-9` | Media | `lazyLoadImages D1`→Media→Lazy, `convertImg D6`→Media→Next-Gen, `maxWidth D8` etc advanced | None | `#imageOptimization` `ImageOptimization.js:954` | `?tab=media&section=lazy|nextgen|responsive` |
| `database_cleanup` `E1-9` `DatabaseCleanup.js:54-127` | Data & System | 9 types `class-database-cleanup.php` + schedule `E8` | None | `#databaseCleanup` | `?tab=data&section=database` |
| `object_cache` `F1-5` `ObjectCache.js:922` | Data & System | Standalone collapsed `F1` + Sentinel `F2` Cluster `F3` advanced | None `wppo-redis-config.php` drop-in `templates/object-cache.php` | `#objectCache` | `?tab=data&section=object-cache&advanced=enterprise` |
| `performance_audit` `G5` `PluginSetting:766` `llms_txt A21` etc | Manage + Diagnostics | `pagespeed_api_key G2`→Manage→API, `rum G5`→Manage→Monitoring, `SystemInfo G3`→Diagnostics drawer, `llms_txt A21`→Advanced→LLMs `class-llms.php` | None | `#tools` `PluginSetting.js:978` | `?tab=manage&section=api|monitoring` + `?tab=diagnostics` |
| `ai_adaptive A20` `edge_cache A19` `od_integration/bfcache/perf_translations` `Main:453` | Advanced | Gated false `enabled false` until `apply_filters` `docs/hooks.md` | None | Dashboard panels `AiPanel:291` `EdgeCachePanel:261` | `?tab=advanced&section=ai|edge` hidden until enabled |

- **Old hooks remain unchanged:** 30+ `wppo_*` `docs/hooks.md:493` `wppo_should_cache_request:1524` `wppo_invalidation_urls:1920` etc — no rename.
- **Old bookmarks:** `App.js:76` `useState activeTab` without URL today — new routing adds `history.pushState` `?tab=` while `useEffect` maps old hash `fileOptimization→speed` for fallback — existing bookmarks via `wppoSettings.settings[tabName]` `AGENTS.md:React SPA` not broken because REST `tab` param `class-rest.php:74` generic `ALLOWED_SETTINGS_TABS:470` accepts old tab names as alias.
- **Existing config:** No forced migration wizard; Welcome wizard `WelcomePanel.js:9` "Re-open wizard Manage→Tools" opt-in, not auto.
- **Uninstall:** `uninstall.php:193-217` multisite `switch_to_blog` + transient `Util::transient_key()` already covered.

