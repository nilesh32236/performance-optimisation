# INFORMATION-ARCHITECTURE.md — Current → Proposed

## Current (7 flat tabs `App.js:96-135` no routing `App.js:76`)
```
Dashboard (1329) — cache toggles + 9 diagnostics stacked .wppo-stacked-cards
File Optimization (1970) — Assets/Scripts/E-commerce/Network/Core 5 sub-tabs 224-250
Preload (623) — Warm-up + 3P + Critical + Speculation flat
Image Optimization (954) — Lazy/Video/Next-Gen/Responsive 4 sections
Database (644) — 9 types + schedule + hero Optimize Everything Now
Object Cache (922) — Standalone/Sentinel/Cluster/TLS/Status flat
Tools (978) — Activity Log + PageSpeed Key + Monitoring + Export/Import + System Info
```
**Problems:** 7 equal weight violates frequency; File 1970 vs Preload 623 wrong weight; Dashboard 1329=~2800 with subcomponents >4000px; 5 File sub-tabs without breadcrumbs/deep-link; CDN duplicated 1047 vs 1682; Preload 4 mental models flat; Image Responsive disconnected.

## Proposed (4+1 pillars + search)
```
Overview (Health) — Health header Speed/Stability/Efficiency 0-100 + 3 actions + Welcome wizard entry
Speed — CSS (B1/B5/B6) | JS (B9/B10/B11 advanced) | HTML (B7/B8) | Preload (C1/C2/C5/C6 advanced) | Connections (C3/C4 advanced) | Server/CDN (B13 contextual LS, B14 advanced, B15 contextual)
  └ Advanced: Combine B2, UsedCSS B4, Delay B11, Woo B12 contextual, Server Rules raw
Media (Images) — Lazy D1/D2 + Video D5 | Next-Gen D6/D7 advanced | Responsive D8 advanced | Preload D9 advanced
Data & System — Database E1-9 (Safe default + Advanced orphan) | Object Cache F1-4 (Standalone collapsed + Enterprise advanced + Status diagnostic)
Manage — Activity G1 | API Keys G2 | Monitoring G5 | Tools G4 Export/Import + SystemInfo diagnostic G3 | Advanced (AI A20, Edge A19, LLMs A21, Autoload A15)
Diagnostics (collapsible under Health) — PerformanceAudit 841 raw table + PageSpeed Trends+RUM + Autoload + SystemInfo on demand
```
**Why:** Outcome over implementation (§2): Speed = "Make pages faster", Media = "Improve images", Data&System = "Clean up & infrastructure", Manage = "Find problems". File monolith split into CSS/JS/HTML pillars with progressive disclosure; Preload 4 concepts → Preload + Connections grouping; Dashboard 8 cards → Health header 3 pillars replacing 4 stat cards `Dashboard:823-971`.

## Navigation Changes
| Before | After | Why |
|--------|-------|-----|
| 7 flat equal `App.js:96` | 4 pillars + Manage + search | Frequency: Overview daily vs Object Cache rare |
| File 5 sub-tabs state only | URL `?tab=speed&section=css&advanced=1` + roving tablist `App.js:251-274` extended | Deep-link + breadcrumb |
| Dashboard 1329 stacked | Health header 3 rings + actions + diagnostics drawer | ~2800 lines → 1 header + drill-down |
| CDN 2 places | Speed→Network single | Single source |
| Tools mixed | Manage grouped API/Monitoring/Tools + Diagnostics separate | Discoverability |
| No search | Global filter "defer, redis, critical" | Find hidden advanced |

## Grouping Logic
- **Speed:** All front-end delivery (CSS/JS/HTML/Preload/Server/CDN) — what Product Manager calls "very high importance" `Agent D`.
- **Media:** All image/video Media D1-D9 — one mental model.
- **Data & System:** DB + Redis — backend, infrequent.
- **Manage:** Logs, keys, monitoring, import/export — agency need.
- **Diagnostics:** Read-only health/audit — not toggles.

## Migration Impact
- `wppo_settings` keys unchanged `Util::ALLOWED_SETTINGS_KEYS:43` 14 tabs → UI grouping only; backend `tab` param `class-rest.php:74-260` generic so no break.
- Old `activeTab` state `dashboard/fileOptimization/preload/...` mapped: `dashboard→overview`, `fileOptimization→speed`, `preload→speed&section=preload`, `imageOptimization→media`, `databaseCleanup→data`, `objectCache→data`, `tools→manage`.
- Hooks `wppo_should_cache_request:1524` etc unchanged `docs/hooks.md`.
- URL redirects via `App.js:76` `useEffect` mapping old hash `#fileOptimization` → `?tab=speed`.

## Visual Hierarchy
- Health header 3 rings (`StatusBadge` + `MetricCard`) + primary CTA "Apply Recommended" + secondary "Advanced" — calm, not 9 cards.
- Cards `FeatureCard` + `FeatureHeader` reused but tiered: normal (white), advanced (border + disclosure), diagnostic (muted).
