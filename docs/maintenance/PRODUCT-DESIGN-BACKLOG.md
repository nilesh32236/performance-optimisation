# Product/Design Backlog

**Master:** `788bf59b` — 6 issues, separated from engineering per instruction 12.
**Current UX:** 7 tabs via `useState+lazy` (`App.js:76` `dashboard,fileOptimization,preload,imageOptimization,databaseCleanup,objectCache,tools`), dark sidebar 260px, max 1120px centered, no deep links, disclosures via warnings, destructive `wppo-danger-zone`, health derived from live telemetry (no fake 92).
**Research docs:** `docs/research/ux-redesign/*` NOT on master (only on `fix/audit-2026-08-28` — 30 docs, `PRODUCT-PHILOSOPHY.md` there). On master philosophy lives in `readme.txt:21-23 Simple/Powerful/Safe` + `AGENTS.md:80`.

## Philosophy Check — `Simple by default. Powerful when needed. Safe at every step.`

| Issue | Fits? | Tension |
|-------|-------|---------|
|709 Design chooser (Native/Premium/Dense) | **Must respect** — chooser table in `designs/README.md` | Variant B Premium (260→64 collapsible command deck) and C Dense (toolbar+table+inspector) add chrome, risk overwhelming interface. Native WP (Cards+Tabs, 8px) closest to philosophy. |
|708 LS-904 WP 7.x readiness | Yes — compatibility, safe |
|707 LS-903 N-features (N1-N10) | Partial — N-features (AI Adaptive, Edge, HTTP3, View Transitions) must be behind Advanced toggle, not default |
|646 v2.0.0 meta | Yes — breaking-change gate |
|369 Banner/icon | Neutral |
|368 Screenshots | Neutral — aids simplicity |

## 709 — Design Chooser: 3 Static Proposals (Native / Premium / Dense)
- **Problem:** Agency audit proposed 3 static HTML previews under `designs/` needing human vote before code mutation.
- **Current UX:** No redesign shipped — maintenance PRs 746/751 were code-quality/perf only, no layout change (verified `git diff 63f3fb2b..788bf59b` has no HealthHeader/SetupWizard on master).
- **User value:** High — defines next 2.0.0 IA. Native WP aligns with WP admin theme adaptation (`color-mix` vs hardcoded hex), Premium offers collapsible but adds nav complexity, Dense offers power-user efficiency but overwhelms new users.
- **Dependency:** Vote comment on #709, then split 765/E5 into E5a tailwind infra → E5b HealthHeader → E5c SetupWizard + implementation log.
- **Design work:** Vote, then `docs/research/ux-redesign/DESIGN-DECISION-MATRIX.md` (on fix/audit) review.
- **Engineering:** After vote, implement chosen variant (<100 files/PR, tailwind postcss, ui primitives `src/components/ui/*`), keep other variants as static previews.
- **Priority:** P3 product decision — **BLOCKED** on human vote.
- **Recommendation:** Choose Native (safest) or Premium with collapsed default + `localStorage` persist; defer Dense. Vote via #709 before any code.

## 708 — LS-904 WP 7.x Readiness + Library Bump
- **Problem:** Tracker for WP 7.x compat + dep bump (`woocommerce/action-scheduler 3.9.3→4.1.0` done at `docs/wordpress-7x-readiness.md:25` + `composer.json ^4.1` lock 2026-08-27).
- **Current:** `composer.json` already `^4.1`, but remaining sub-items duplicate 756/757 (salted family, `wp_get_loading_optimization_attributes`, `wp_dequeue_script_module('emoji')` in `class-core-tweaks.php`).
- **Priority:** P3 meta tracker — close as duplicate of 756/757 or keep as umbrella with checklist.
- **Recommendation:** Keep as tracker, mark 3.9.3→4.1 DONE, link remaining to 756/757.

## 707 — LS-903 Competition White-space N-features
- **Problem:** Tracks novel N1-N10 from `docs/competitive-audit-2026.md:103` (N1 AI Adaptive RUM→auto-tune, N2 Edge HTML Cache, N3 HTTP3/103, N4 View Transitions, N5 OD bridge, N6 bfcache, N7 .mo→php, N8 LLMs.txt, N9 CVE guard, N10 volatility TTL) — ships N8→N5→N1 per audit.
- **Current:** N1 (`includes/class-ai-adaptive.php`), N2 (`class-edge-cache.php`), N6 (`class-bfcache.php`), N5 (`class-od-bridge.php`), N8 (`class-llms.php`) already on master (from prior sprint).
- **Priority:** P3 roadmap — not actionable without prioritization, must be gated behind Advanced/Diagnostics (philosophy: progressive disclosure).
- **Recommendation:** Defer to roadmap planning, not code PR now; evaluate per N's user value vs maintenance cost.

## 646 — Remaining Work Before v2.0.0 Release
- **Problem:** Meta milestone: 213× `@since NEXT`→`2.0.0`, header/`WPPO_VERSION`/`readme.txt:7` Stable tag lockstep, changelog, breaking scope (min WP bump 6.2→?).
- **Current:** No @since rewrite yet (NEXT placeholder per `AGENTS.md:184`).
- **Priority:** P3 meta — depends on 709 and 708 closures.
- **Recommendation:** Keep open as v2.0.0 checklist, verify gate `npm lint → composer lint → npm test → npm run build` (`AGENTS.md:18`) before tag.

## 369 — Create Plugin Banner and Icon for WordPress.org
- **Problem:** Missing `assets/banner-1544x500.png` (WP.org requires 772×250 or 1544×500) + `icon-256x256.png` (`.distignore` includes `assets/`).
- **Current:** No assets/banner, .distignore handles inclusion.
- **Priority:** P3 — manual design, required before v2.0.0 release, not code.
- **Recommendation:** Commission design, export PNG, add to `assets/` + readme.

## 368 — Add Real Screenshots to WordPress.org Listing
- **Problem:** Missing `screenshot-1..7.png` (1200×900) matching `readme.txt:77` descriptions.
- **Current:** No screenshots.
- **Priority:** P3 — manual 1200×900 captures on default theme, deferred until pre-release.
- **Recommendation:** Capture after #709 chosen design (otherwise screenshots mismatch new UI).

## Separation Rationale
All 6 require product/design human input (vote, design assets, roadmap). Mixing into engineering PRs would violate `AGENTS.md:42` 95% gate and `Simple by default` philosophy (risk of overwhelming interface from Dense/Premium or premature N-features). Keep in product backlog, implement only after explicit decision.

## Next Step
- Vote #709 → unblock 765/E5 split → then 708/707/646/369/368 as product decides.
