# UX-METRICS.md — Measurable Before/After

**Before measured on live 1.9.0 `App.js:96-135` `FileOptimization.js:1970` `Dashboard.js:1329`:**

| Metric | Before | Target After (B) | How measured |
|--------|--------|------------------|--------------|
| Top-level nav items | 7 flat `App.js:96` | 4 pillars + Manage (5) + search | Count `sidebarItems` `App.js:96` |
| Settings visible by default (no Advanced) | ~40 toggles `FileOptimization:40-87` 32 + 8 + Dashboard 8 cards | 10 Recommended + Health header 3 rings `DASHBOARD-DESIGN.md` | Grep `SwitchField` per `tab=speed` no `&advanced` |
| Technical terms on L1 | 20 `Agent A` (Minify/Defer/Delay/Critical/Used/TTFB/LCP/WebP/AVIF/Redis/etc `TECHNICAL-COMPLEXITY-AUDIT.md`) | 5 simplified ("Make files smaller", "Load after page") `TERMINOLOGY.md` | Count terms in L1 tooltip vs advanced |
| Clicks to common task "Enable cache + minify" | 7 tabs hunt + 5 sub-tabs `FileOptimization:224` + scroll 4000px `Dashboard` = ~12 clicks | 2 (Open wizard → Apply Recommended) `ONBOARDING.md` 6 steps | Journey timing `USER-JOURNEYS.md` New User |
| Required decisions during onboarding | >30 `File:40-87` + `Preload 623` + `Image 954` = 30+ before value | 0 required (Recommended auto) + 6 wizard optional `WELCOME` | Count `defaultSettings` keys vs wizard steps `WelcomePanel:9` |
| Unexplained options (no benefit/risk) | ~25 `FileOptimization:359-382` only implementation | 0 — every toggle has benefit+ risk+ recommendation `PRODUCT-PHILOSOPHY.md:Principle 4` | Audit `Tooltip` when paused `359-382` vs new |
| Time to first value (TTFB after install) | Manual 3 steps `WelcomePanel:9` cache/minify/lazy still 3 saves | <2min auto Telemetry `PerformanceAudit:292` + Apply Recommended single `apiCall` | `wp cron event list` re-scan delta |
| Health comprehension (Good/Needs vs raw) | Raw TTFB 200/500 `PerformanceAudit:121` dominates `MetricOverview 206-264` | Badge Good/Needs first `StatusBadge` `:675-683` number in tooltip `INFORMATION-ARCHITECTURE.md` | User test "What to do next?" |
| Advanced discoverability (find Redis Sentinel) | Flat 7 tabs `ObjectCache:922` same level Standalone vs Sentinel `508` | Search "sentinel" + `?advanced=enterprise` `ADVANCED-MODE.md` | Search filter test |
| Failed queue visibility (681 failed `Agent N`) | Spinner forever `PageSpeedPanel:479` | Error "Missing API key Manage→API Keys" `SAFETY-RECOVERY.md` | Trigger `pagespeed_api_key ""` `PluginSetting:108` |
| A11y violations | `Tooltip span tabIndex 0` `SwitchField no aria-describedby` `table scope` `K` 3 issues | 0 — span→button, `aria-describedby` `SwitchField:41`, `scope` `PerformanceAudit:506` | `npm run lint:js` + axe |

**Targets non-negotiable for release gate.**
