# IMPLEMENTATION-PLAN.md — 8 Phases Detailed for Next Coding Run

**Read order:** `PRODUCT-PHILOSOPHY.md` → `FEATURE-TREATMENT-MATRIX.md` → `INFORMATION-ARCHITECTURE.md` → here + `IMPLEMENTATION-MATRIX.md: file:line actionable` — no prod code yet `git diff empty`, `@since NEXT` never bump `1.9.0` `performance-optimisation.php:5`, `AGENTS.md:29` verification `lint:js→phpcs→jest→build` + `composer test`.

## Phase 1 — Information Architecture (1 sprint)
- Replace 7 flat `App.js:96-135` `useState activeTab` no routing `App:76` with 4+1 pillars `Overview/Speed/Media/Data&System/Manage` + Diagnostics drawer `INFORMATION-ARCHITECTURE.md` — keep `wppo_settings` keys `Util:43` same, backend `tab` param `class-rest.php:74` generic alias old→new `dashboard→overview` etc via `App.js useEffect` mapping, `history.pushState ?tab=speed&section=css` deep-link replacing state-only `App:76`.
- Global search filter "defer, redis, critical" `ADVANCED-MODE.md`.
- Verify: `npm run lint:js` 0e3w, `vendor/bin/phpcs --report=summary includes/` 0e1w, `npm test 34/345`, `wp wppo settings get file_optimisation` 200.

## Phase 2 — Navigation / Dashboard (1 sprint)
- Health header 3 rings Speed/Stability/Efficiency `DASHBOARD-DESIGN.md` replacing 4 stat cards `Dashboard:823-971` Cache Size/Optimized Files/DB Overhead/Images + 8 cards `PerformanceAudit 841 + PageSpeed 479 + WebVitals 229+177 + Suggestions 270 + SystemInfo 353` collapsed to header + actions (Purge All `Dashboard:774`, Run Scan `PerformanceAudit:292`, Apply Recommended) + Recent Activity 3 rows `RecentActivityCard.js`.
- Diagnostics drawer "Show details" with raw `PerformanceAudit:506` 16-row table + `Autoload:133` + `SystemInfo:353` on demand.
- Keep `StatusBadge.js` `MetricCard.js` semantic `--wppo-success #059669` `_variables.scss:34-46` + `:focus-visible` `base:108`.

## Phase 3 — Settings Simplification (1 sprint)
- Split FileOptimization monolith `1970` `FileOptimization.js:40-87` 32 defaults + 5 sub-tabs `224-250` → Speed pillars CSS `B1/B5/B6` + JS `B9/B10` + HTML `B7/B8` + Preload `C1/C5` grouped intent `§16`, textareas `411-575` → picker autocomplete `Asset_Manager:245` `class-metabox.php:453`.
- Terminology outcome `TERMINOLOGY.md` short + Learn more + Advanced details per `§15` — not giant under every setting `359-382`.
- Media `ImageOptimization.js:954` Lazy `D1/D2` + Video `D5` + Next-Gen `D6` RECOMMEND vs `D7/D8` advanced `AUTOMATION-CANDIDATES.md` — thumbnails for placeholder `295`.

## Phase 4 — Advanced Mode (1 sprint)
- Tiered disclosure 4 levels `ADVANCED-MODE.md` L1 Recommended (10 safe `B1/B7/B9/B10/D1/D6` etc) L2 More control L3 Advanced (Combine `B2` `399` FOUC + UsedCSS `B4` `496` + Delay `B11` `936` + Woo `B12` contextual `1145` only when Woo, Server raw `1521` `1631` + Redis Sentinel/Cluster `ObjectCache:488-643` behind Enterprise) L4 Diagnostics — `?tab=speed&advanced=1` + `show_advanced` persist `wppoSettings`.
- Keep dev power per `Agent B`: per-page metabox `767`, delay strategies `72-104`, Used/Critical `CriticalCssPanel`, Server Rules raw `1631-1649`, hooks 30+ `docs/hooks.md`.
- Discoverability via search + deep-link `?advanced=enterprise`.

## Phase 5 — Onboarding / Recommendations (1 sprint)
- Wizard 6 steps `ONBOARDING.md` Welcome→Environment (LiteSpeed `x-litespeed-cache: hit` `Server_Rules:191`, Redis PONG `ObjectCache:922`, GD/Imagick `Img_Converter:84`, no WP Rocket) → Analyze auto Telemetry `PerformanceAudit:292` homeUrl 1h + PSI if `pagespeed_api_key:108` else skip → Recommendations 3 tiers Safe/Review/Advanced `Dashboard:78-91` Suggestions merged → Review benefit/risk/badge → Apply bundle `WelcomePanel:33-40` single `apiCall update_settings` snapshot + Verify re-scan delta `WebVitalsTrends:229`.
- "Re-open wizard Manage→Tools" not auto `WelcomePanel:78` dismiss.
- One-click "Apply Recommended" 10 safe excludes `combine/delay/usedCSS/critical/ServerRules` `ONBOARDING.md` safe set.

## Phase 6 — Error / Conflict / Recovery UX (1 sprint)
- Conflict banner expanded `CONFLICT-DETECTION.md`: `is_plugin_active` WP Rocket/FlyingPress/SG/Autoptimize vs only LS `Dashboard:686-693` + Cloudflare `CF-Cache-Status` `CDN_Purger:238` + friendly "Another plugin handles this — leave off. Why? Details → Advanced override" `§18`.
- Warnings consequence not implementation `SAFETY-RECOVERY.md` per Principle 4, `ConfirmDialog:618` sample before DB `Optimize Everything Now` `DatabaseCleanup:468-487` + Object Cache Disable `858-898` red.
- Snapshot `wppo_settings_snapshot_{time}` transient 10m before `update_settings Rest:464` + Undo + `?wppo_safe=1` kill-switch `Util::is_safe_mode()` checked `setup_hooks:489` `class-cache.php` bypass + admin bar "Disable 10 min" + CLI `wp wppo reset --safe`.

## Phase 7 — Accessibility (0.5 sprint)
- Fix `Tooltip.js:30` span→button `K`, add `aria-describedby` `SwitchField.js:41` description `46`, `scope` `PerformanceAudit:506` tables `ACCESSIBILITY.md`, keep roving `App:251-274` focus trap `215-260`/`ConfirmDialog:1-182` `:focus-visible` `base:108`, announce health `aria-live`.

## Phase 8 — Testing / Migration (0.5 sprint)
- Real-env plan `TEST-PLAN.md` activation/upgrade `wppo_settings 11→14` backfill `Util:92`, existing config `wppoSettings.settings[tabName]` `AGENTS.md` still `class-rest.php:74` generic, cache `Cache::clear_cache:2087` + `src/main.js` 403, frontend `min/1/css` 16K `class-cache.php:748`, cron 15 events `wppo_page_cron_hook 5h`, multisite `Util::transient_key:781`, a11y, rollback `?wppo_safe=1`.
- `MIGRATION-PLAN.md` 7→4 mapping `fileOptimization→speed` etc no DB migration, hooks `wppo_*` 30+ unchanged, old URL alias.

**Gate:** Each phase `php -l` + `phpcs` + `jest 34/345` + `build wp-scripts build src/index.js src/lazyload.js src/main.js src/rum.js 134K index.js` + `phpunit 513/913 2 skipped` `AGENTS.md:29` before `gh pr` + checks.

