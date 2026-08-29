# USER-JOURNEYS.md — 4 Journeys

## Journey 1 — New User Install → Verify
```
Install (WP Admin → Plugins → Activate 1.9.0 `performance-optimisation.php:5`) →
  Detect (Server LiteSpeed `x-litespeed-cache: hit`, no WP Rocket, Redis PONG, GD/Imagick, WP7.1) →
  Analyze (auto Telemetry `PerformanceAudit.js:292` homeUrl cached 1h + PSI if key G2) →
  Show current state (Health header Speed Stability Efficiency Good/Needs) + Welcome checklist `WelcomePanel.js:9` →
  Recommend safe improvements (Apply Recommended: cache ON `Dashboard:974`, minify CSS/JS `B1/B9` ON, defer `B10` ON, lazy native D1 ON) →
  Apply selected (single `apiCall update_settings` with snapshot for rollback) →
  Verify (re-scan TTFB/LCP delta + RUM `WebVitalsTrends.js:229` + "Undo" `ConfirmDialog`).
```
**Clicks:** 2 (Apply Recommended → Verify) vs today 40 toggles. **Time:** <2min vs 20-step `FileOptimization.js:40-87`.

## Journey 2 — Existing User Understand → Improve
```
Open plugin (Overview health header 3 rings + stat strip cache size `Dashboard:823` DB overhead `675`) →
  Understand (Suggestions merged `Dashboard:78-91` 1-3 actions grouped by pillar) →
  Improve (CTA "Defer JavaScript — saves 1s → Apply" benefit/risk/badge) →
  Verify (Clear All Cache `class-rest.php:371` admin bar `src/main.js` + reload).
```
**Today:** lands on 8-card Dashboard 1329 scroll to find settings.

## Journey 3 — Developer Diagnose → Configure → Verify
```
Advanced (Speed→Advanced disclose `FileOptimization.js:399` Combine, `936` Delay, `496` UsedCSS safelist) →
  Diagnose (Diagnostics drawer raw `PerformanceAudit:506` 16-row table + `SystemInfo:353` env + Autoload `133` + `wp wppo system-info --format=json` `class-wppo-cli-command.php:76`) →
  Configure (Per-page Asset Manager `class-metabox.php:453` URL regex `1187`, Redis Sentinel `ObjectCache:508` TLS, hooks `docs/hooks.md` `wppo_exclude_delay_js`) →
  Verify (`wp wppo cache clear --dry-run` `206-280` + `curl -sI` `x-litespeed-cache` + PSI poll `wppo_pagespeed_scan` Action Scheduler).
```
**Preserved:** All dev power, but separated not flat.

## Journey 4 — Broken Optimization Detect → Explain → Disable/Rollback → Recover
```
Detect (Front-end fatal or FOUC after Combine B2 `399-409` or Delay B11 `1124` checkout break) →
  Explain (Health warning "Combine caused flash — 2 pages affected — Details" vs "Option X conflicts Y") →
  Disable/Rollback (One-click "Disable last change" via snapshot + `?wppo_safe=1` kill-switch checked `setup_hooks:489` via `Util::is_safe_mode()` cookie 10min + admin bar Disable) →
  Recover (Restore defaults `class-main.php:857`, Clear cache, re-verify scan, conflict banner "Another plugin handles this `Dashboard:706-758` LiteSpeed style extended to WP Rocket`).
```
**Safety:** Snapshot before `update_settings` `Rest.php:464-518`, ConfirmDialog `DatabaseCleanup:618` sample before delete, emergency CLI `wp wppo reset --safe`.

## Edge Journeys
- **Multisite:** `Util::transient_key:781` `{blog_id}_` isolated, switch_to_blog loop `uninstall.php:193-217` canonical, `--network` docs-only per `FINAL-ADVERSARIAL-REVIEW.md`.
- **Shared host no Redis:** Object Cache shows "Redis not available" diagnostic not toggle.
- **Nginx:** Server Rules shows code block `FileOptimization:1631-1649` manual copy, not FTP.

