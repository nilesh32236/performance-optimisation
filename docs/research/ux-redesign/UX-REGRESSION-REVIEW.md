# UX-REGRESSION-REVIEW.md — Normal User + Developer Workflows (2026-08-29)

**Live:** nileshportfolio.duckdns.org WP 7.1 PHP 8.3.33 LiteSpeed hit `wppo-audit-details` present search `faSearch` 5 pillars

## Normal User — Website Owner Knows Nothing About Performance
| Task | Where they went | What they saw | Confused | Clear | Decisions | Clicks | Terms |
|------|-----------------|---------------|----------|-------|-----------|--------|-------|
| 1 Find improvement | Overview health 3 rings `Dashboard.js:700` Good/Needs vs 4 stats `823` old | HealthHeader status Good/Needs attention `HealthHeader.js` badge not 92 scores — truthful | No | Yes — health rings + badge color `var(--wppo-success)` | 0 | 1 scroll | 0 technical L1 (Health vs Speed) |
| 2 Understand recommendation | Suggestions `Dashboard:78` merged `SuggestionsPanel` below `PerformanceAudit:506` + HealthHeader Apply Recommended | "Make files smaller — saves 10–20%" `FeatureCard:75` badge Recommended + short benefit `TERMINOLOGY.md` | No | Yes — benefit/risk not implementation | 0 | 1 | 0 "Minify" simplified to "Make files smaller" |
| 3 Apply Recommended | HealthHeader Apply → `SetupWizard.js:1033` modal Welcome→Env (LiteSpeed `686` plain "Server is LiteSpeed" vs "LS 1.9.1 lsphp83" `is_litespeed:201` hidden) → Analyze auto `292` → Review → Apply `LoadingSubmitButton` | Wizard 6 steps but primary "Apply Recommended" 1 click in step 4 | Wizard Env jargon `PONG/Imagick` still slightly technical `SetupWizard.js:47` but Env plain text "LiteSpeed detected" now | Yes via wizard | 2 (Apply → Verify) | 2 vs 12 before | 1 "Redis PONG" still shown but as "Redis connected" now |
| 4 Understand it worked | Verify re-scan `WebVitalsTrends:229` + `RecentActivityCard` + snapshot `Rest:518` 600 | Recent Activity "Cache cleared" `RecentActivityCard` + Health still Good | No | Yes | 0 | 1 | 0 |
| 5 Find important setting Redis | Search "Redis" `App.js:627` `faSearch` input → `Disclosure` hidden preserving `isOpen` `Disclosure.js` | Data & System → Database & caching pillar `App.js:262` `ObjectCache:28` Standalone collapsed + Sentinel `488` enterprise Disclosure hidden until search "sentinel" shows `ObjectCache:488` only 1 match + empty others hidden `wppo-disclosure--hidden` | Slight: search hides 4 of 5 Disclosure but `PreloadSettings` `ImageOptimization` 40 toggles not yet filtered via `compact` — shows still L1 10 toggles but advanced redis filtered works | Yes for redis via Disclosure | 1 search | 1 search | 0 "Redis" outcome "Speed up repeated data" `TERMINOLOGY.md` |
| 6 Recover from problematic optimization | Manage → Undo Last Change `PluginSetting.js:615` card "Available for 10 minutes" + `handleUndo` `apiCall wppo_snapshot_undo` 600 `SameSite Lax` | Manage Tools Undo button + health fallback `?wppo_safe=1` `Util:989` cookie 600 `curl -I SameSite=Lax` | No | Yes — confidence `SAFETY-RECOVERY.md` | 0 | 1 Undo | 0 |

**Regression vs `UX-METRICS.md` before/after:** nav 7→5 29% fewer, L1 40→10 +3 rings, terms 20→5, clicks 12→2, decisions 30→0, unexplained 25→0, A11y 3→0 — all targets met per `UX-METRICS-RESULTS.md` 11 rows; remaining jargon `PONG` minimal.

## Developer — Fine-Grained Control
| Task | Where | Saw | Discoverable | Clicks | Hooks |
|------|-------|-----|--------------|--------|-------|
| 1 CSS | Speed→CSS `B1/B5/B6` keep `B2` Combine `FileOptimization:416` Disclosure search "combine" → visible `Disclosure` `416` badge Advanced | Yes `Disclosure` hidden not destroyed | 1 search | `wppo_exclude_minification` `docs/hooks.md` preserved |
| 2 Delay | Speed→JS `B11` `FileOptimization:989` 5 fields Disclosure `989` search "delay" → visible `Disclosure` | Yes `Delay JavaScript` Disclosure `searchQuery` `989` | 1 | `wppo_exclude_delay_js` preserved |
| 3 UsedCSS | `524` search "unused" → visible | Yes | 1 | — |
| 4 Server rules | `1697` raw `Server Rules` `FileOptimization:1697` search "server" → visible + Nginx `1631` raw `Server Rules` code block | Yes `Server Rules` Disclosure `1697` | 1 | `wppo_htaccess` `wppo_nginx_rules` preserved |
| 5 Redis advanced | `ObjectCache:488` Sentinel `F2` Cluster `F3` TLS `669` search "sentinel" → `ObjectCache:488` Disclosure enterprise | Yes via search `App.js:262` `ObjectCache:28 compact` → `Disclosure` enterprise | 1 | — |
| 6 Diagnostics | DiagnosticsAnchor `Dashboard.js:33` `wppo-audit-details` + `PerformanceAudit:506` `Autoload:133` `SystemInfo:353` `scope col/row` | Yes id present scroll `Dashboard:714` fallback `wppo:navigate-manage` | 1 | — |
| 7 System info | `SystemInfo.js` + `conflicts` `SystemInfo:360` `get_conflicts` `wp-rocket` etc via `Main:1617` `wppoSettings.conflicts` | Yes `Dashboard:33` conflicts via `HealthHeader` warning planned but `Dashboard` prop not yet wired — backend verified `SystemInfo:73` REST `system_info` `system-info --format=json` shows `conflicts` | 1 | — |
| 8 WP-CLI | `wp wppo system-info --format=json` `wppo database counts` `wppo cache clear` `sudo -u nobody` success `regression` | Yes `class-wppo-cli-command:1093` `wp wppo` 7 verbs preserved | 0 CLI | `wp wppo` preserved |
| 9 Hooks | `docs/hooks.md` `wppo_*` 30+ preserved no regression `wppo_should_cache_request:1524` `wppo_invalidation_urls:1920` etc | Yes `docs/hooks.md` | 0 | All 30+ preserved |

**Quality bar §27:** Normal "I know what to do without understanding engineering" — true via Overview health + wizard 2 clicks; Developer "I can still reach technical controls when needed" — true via Disclosure search + deep-link `?tab=speed&advanced=1` + `?tab=data&section=object-cache` `MIGRATION-PLAN.md` 7→4 alias.

