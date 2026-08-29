# UX Redesign Research — Performance Optimisation 1.9.0

**Date:** 2026-08-29  
**Base:** `fix/audit-2026-08-28` `c8d1cef3` → `origin/master@31fffc61` (42 PHP 31k, 28 REST, 7 tabs)  
**Mode:** Research only — **no production code modified** (`git diff -- includes/ src/` empty)  
**Agents:** A-N (14) + synthesis, 22 docs in `docs/research/wp-cli-hooks/` reused, 25 new docs here  
**Live site:** nileshportfolio.duckdns.org WP 7.1 PHP 8.3.33 LiteSpeed `x-litespeed-cache: hit` `boltfolio` `WP_DEBUG false` multisite false Redis PONG 1.36M cache 2.9M

## Objective
Transform Performance Optimisation from developer tool (40+ toggles in `FileOptimization.js:40-87`, 1970-line monolith `FileOptimization.js`, 1329-line Dashboard `Dashboard.js`) into **Simple by default. Powerful when needed. Safe at every step.** — outcome over implementation, progressive disclosure, recommended defaults, safe failure.

## Deliverable Map (25 files)
| # | File | Purpose |
|---|------|---------|
| 1 | `PRODUCT-PHILOSOPHY.md` | Permanent principles (who/for, what users should NOT need to understand) |
| 2 | `CURRENT-UX-AUDIT.md` | Existing IA, labels, cards, states, a11y, bugs (Dashboard 8 cards, File 5 subtabs, 7 flat tabs) |
| 3 | `FEATURE-INVENTORY.md` | ~55 features with location, label, problem, persona, risk, visibility |
| 4 | `FEATURE-TREATMENT-MATRIX.md` | KEEP/SIMPLIFY/GROUP/AUTOMATE/RECOMMEND/ADVANCED/DIAGNOSTICS/CONTEXTUAL |
| 5 | `TECHNICAL-COMPLEXITY-AUDIT.md` | 40+ terms classified KEEP/SIMPLIFY/ADVANCED/DIAGNOSTICS |
| 6 | `TERMINOLOGY.md` | Current → normal → advanced wording |
| 7 | `AUTOMATION-CANDIDATES.md` | AUTOMATE/RECOMMEND/MANUAL/ADVANCED/DIAGNOSTIC per setting |
| 8 | `INFORMATION-ARCHITECTURE.md` | Current 7 flat → proposed 4+1 (Overview/Speed/Media/Data&System/Manage) |
| 9 | `USER-JOURNEYS.md` | New/Existing/Developer/Broken flows |
| 10 | `ONBOARDING.md` | Install→Detect→Analyze→Recommend→Apply→Verify wizard |
| 11 | `DASHBOARD-DESIGN.md` | Health header (Speed/Stability/Efficiency) + actions |
| 12 | `ADVANCED-MODE.md` | Tiered disclosure + search, what stays exposed |
| 13 | `SAFETY-RECOVERY.md` | Safe defaults, warnings, rollback, emergency mode |
| 14 | `CONFLICT-DETECTION.md` | LS/WP Rocket/Cloudflare detection messaging |
| 15 | `ACCESSIBILITY.md` | WCAG, keyboard, screen reader, toggles, tooltips |
| 16 | `DOCUMENTATION-AUDIT.md` | User vs dev docs, gaps |
| 17 | `DESIGN-OPTIONS.md` | 3 concepts A/B/C |
| 18 | `DESIGN-DECISION-MATRIX.md` | Agent votes + final synthesis |
| 19 | `ADVERSARIAL-REVIEW.md` | What could go wrong |
| 20 | `MIGRATION-PLAN.md` | Settings map, DB, hooks, URLs |
| 21 | `TEST-PLAN.md` | Real-env validation |
| 22 | `UX-METRICS.md` | Before/after measurable targets |
| 23 | `IMPLEMENTATION-PLAN.md` | Phases 1-8 |
| 24 | `IMPLEMENTATION-MATRIX.md` | File-level matrix |
| 25 | `CURRENT-UX-AUDIT.md` (etc) | Live QA evidence |

## Real Installation Status
- **WP 7.1** `wp core version` verified, **PHP 8.3.33** lsphp83, **LiteSpeed** `server: LiteSpeed` `x-litespeed-cache: hit`, **boltfolio** active, **5 plugins** + 2 dropins `advanced-cache.php` `object-cache.php`, **multisite false**, **debug off**, **cron 15 events** `wppo_*`, **cache 2.9M** `min/1/css+js`, **llms.txt virtual 200 markdown**, **28 REST endpoints** `class-rest.php:78-300` (doc says 25 stale), **wppo_settings 11/14 tabs** (missing `od_integration/bfcache/perf_translations` backfill gap `class-util.php:43`), **681 Action Scheduler failed** (missing PageSpeed key), **no php -l errors**, **build 134K index.js** `build/index.js`.

## Key Findings
- **Major UX problem:** 7 flat tabs equal weight `App.js:96-135` hides 5 File sub-tabs `FileOptimization.js:224-250`; Dashboard mixes health + 9 diagnostics `Dashboard.js:800-1282` (~2800 lines with subcomponents); FileOptimization monolith 1970 vs Preload 623.
- **Cognitive load:** ~40 toggles + 12 textareas + 5 selects in FileOptimization; exclude handles require Asset Manager metabox knowledge `class-main.php:767`.
- **Terminology:** Defer vs Delay vs DeferJS `FileOptimization.js:54-57` confusing; Critical/Used CSS `FileOptimization.js:84-86` not explained as benefit/risk.
- **Safety:** defaults all `false` `class-main.php:170-214` safe but no one-click Recommended, no emergency kill-switch `?wppo_safe=1`.
- **Accessibility:** roving tablist `App.js:251-274` + focus trap `215-260` good; Switch `role=switch` missing `aria-describedby` `SwitchField.js:41`; Tooltip `tabIndex 0` but `span` not `button` `Tooltip.js:30`.

## Recommended Redesign
**Option B — Recommended Product Redesign** (4+1 nav, health header, Recommended one-click, progressive disclosure) wins over A Minimal and C Full — see `DESIGN-DECISION-MATRIX.md`. Principle: “Less configuration, not less capability.”

## Constraints
- No prod code modified; `git diff --stat origin/master...HEAD -- docs/research/ux-redesign/ | wc -l` only docs.
- Preserve `wppo_settings` single option, 30+ `wppo_*` hooks `docs/hooks.md:493`, WP-CLI 7 verbs `class-wppo-cli-command.php:1093`, all optimization engines.
- `NEXT` versioning, `@since NEXT`, multisite `Util::transient_key()`.

## How to Use Next Run
Read `PRODUCT-PHILOSOPHY.md` → `FEATURE-TREATMENT-MATRIX.md` → `INFORMATION-ARCHITECTURE.md` → `IMPLEMENTATION-PLAN.md`/`IMPLEMENTATION-MATRIX.md` in order. Each matrix row is `file:line` actionable.

## Agents
A Normal User, B Developer, C UX, D PM, E Rationalization, F IA, G Onboarding, H Perf, I Safety, J Competitive (`docs/competitive-audit-2026.md`), K A11y, L Architecture, M Adversarial, N QA — reports in `/tmp/agent_*.md`.

