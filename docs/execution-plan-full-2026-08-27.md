# Execution Plan — Performance Optimisation 2026-08-27 → 2026-12-09 (WP 7.2 GA)

**Baseline:** `performance-optimisation.php:1` v1.9.0 · `master@bbb4783e` · LiteSpeed Phases 0-4 shipped (LS-001..404) · WP 7.1 · PHP 8.2 · Node 22.14.0  
**Companion docs:** `docs/litespeed-research.md:1`, `docs/litespeed-integration-plan.md:1`, `docs/litespeed-roadmap.md:1`, `docs/competitive-audit-2026.md:1`, `docs/performance-report-2026-08-27.md:1`, `docs/wordpress-7x-readiness.md:1`, `COMPETITIVE_GAP_ANALYSIS.md:1`, `AGENTS.md:29` (verification order)  
**Principles:** Ship incrementally, zero breakage on non-LS hosts, every new API behind `function_exists()`/`class_exists()` per `AGENTS.md:174`, new symbols `@since NEXT`, settings default-safe, multisite via `Util::transient_key()` (`includes/class-util.php`), verification order `npm run lint:js → composer lint → npm test → npm run build` (`AGENTS.md:29`) + `composer test`.

---

## Table of Contents
1. [Decision — What to build, what to skip](#1-decision--what-to-build-what-to-skip)
2. [Research Plan — How we keep the matrix current](#2-research-plan--how-we-keep-the-matrix-current)
3. [Architecture & Guardrails](#3-architecture--guardrails)
4. [Timeline Overview (Sprints → Releases)](#4-timeline-overview-sprints--releases)
5. [Sprint 0 — Audit Green (2026-08-28..29)](#5-sprint-0--audit-green)
6. [Sprint 1 — Foundation: WP Core + Library Bump (2026-08-30..09-06)](#6-sprint-1--foundation-wp-core--library-bump)
7. [Sprint 2 — Parity: Docs + CSS Intelligence (2026-09-07..09-18)](#7-sprint-2--parity-docs--css-intelligence)
8. [Sprint 3 — Novel Edge A: LLMs.txt + OD (2026-09-19..10-02)](#8-sprint-3--novel-edge-a-llmstxt--od)
9. [Sprint 4 — Novel Edge B: AI Adaptive + Edge Cache (2026-10-03..10-24)](#9-sprint-4--novel-edge-b-ai-adaptive--edge-cache)
10. [Backlog — Tier 3 + WP 7.2 Secrets (after 7.2 GA 2026-12-09)](#10-backlog--tier-3--wp-72-secrets)
11. [Execution Workflow (branch → PR → CI → merge → tag)](#11-execution-workflow-branch--pr--ci--merge--tag)
12. [GitHub Issue/Project Tracking](#12-github-issueproject-tracking)
13. [Testing Strategy](#13-testing-strategy)
14. [Risks & Mitigations](#14-risks--mitigations)
15. [Definition of Done (per PR/phase)](#15-definition-of-done-per-prphase)
16. [Open Questions](#16-open-questions)

---

## 1. Decision — What to build, what to skip

### 1.1 Scoring model (used to order the backlog)

Score = `(UserValue × 3) + (Moat × 2) + (EffortInverse × 1)` / 5, each 1-5. UserValue = % of installs that benefit; Moat = how few competitors ship it; EffortInverse = 5 is S (<1 day), 1 is L (~1 week, specs + tests).

| Candidate | Value | Moat | Effort | Score | Verdict |
|---|---|---|---|---|---|
| Audit green (722) + TTFB docs (706) | 5 | 1 | 5 | 3.8 | **P0 — Sprint 0** |
| `action-scheduler` 3.9.3→4.1.0 | 4 | 1 | 5 | 3.2 | **P0 — Sprint 1** |
| Salted-cache family + emoji footer | 4 | 3 | 4 | 3.6 | **P1 — Sprint 1** |
| `wp_get_loading_optimization_attributes` (occluded) | 4 | 3 | 4 | 3.6 | **P1 — Sprint 1** |
| `module_dependencies` (7.0 classic↔module) | 3 | 2 | 3 | 2.8 | **P1 — Sprint 2** |
| OD integration | 5 | 5 | 3 | 4.6 | **High — Sprint 3** |
| LLMs.txt (N8) | 5 | 5 | 5 | 5.0 | **Flagship S — Sprint 3** |
| `.mo→php` (N7) + bfcache (N6) | 4 | 5 | 3 | 4.2 | **Sprint 3-4** |
| AI Adaptive (N1) | 5 | 5 | 1 | 3.8 | **L flagship — Sprint 4** |
| Edge HTML Adapter (N2) | 4 | 5 | 1 | 3.4 | **L — Sprint 4** |
| REST/Feed cache, per-URL TTL, Early Hints 103 (N3) | 3 | 3 | 3 | 3.0 | **Backlog** |
| QUIC.cloud/ESI Enterprise parity | 2 | 1 | 1 | 1.6 | **Skip** — document as Enterprise advantage, keep local heuristic |

**Non-goals (explicit no-build):** Re-implementing LSCWP QUIC.cloud UCSS/CCSS/Image CDN (we keep local credit-free `includes/class-critical-css.php` / `class-used-css.php`); forking LSCache server ESI (Enterprise-only per `litespeed-research.md:3.6`); vendoring competitor code.

### 1.2 Build order
`Sprint 0 (green) → Sprint 1 (foundation) → Sprint 2 (parity+CSS) → Sprint 3 (N8+N5 quick wins) → Sprint 4 (N1+N2 flagship L) → 7.2 Secrets post-GA`.

---

## 2. Research Plan — How we keep the matrix current

### 2.1 What to research, cadence, owner, tool

| Stream | Cadence | Tool / source | Owner | Output |
|---|---|---|---|---|
| **Competitor plugin surface** (WP Rocket, FlyingPress, LSCache, Perfmatters, NitroPack, W3TC, Hummingbird, WP-Optimize, SiteGround Optimizer, BerqWP) | Weekly scan + per-release deep dive | `default.websearch` (livecrawl preferred), `default.webfetch` changelog pages, `wordpress.org/plugins/{slug}` zip fetch to `/tmp`, `gh api /repos/{org}/{plugin}/releases` | Sprint lead | `docs/competitive-audit-2026.md:2` matrix deltas + `COMPETITIVE_GAP_ANALYSIS.md:2` row flips |
| **WordPress core** (6.8.1 → 7.2 GA 2026-12-09) | On `wordpress-monitor.yml` weekly Sunday + on Make Core post | Context7 `Upstash` (`CONTEXT7_API_KEY`) + `make.wordpress.org/core` field guides + `developer.wordpress.org` since pages + `core.trac` | WP monitor agent | `docs/wordpress-7x-readiness.md:1` section per minor + gated TODOs with `function_exists` snippet |
| **Performance Lab** (OD, Image Prioritizer, Embed Optimizer, View Transitions, Performant Translations, Speculative Loading, Auto Sizes) | Bi-weekly | `github.com/WordPress/performance` + `npm view @wordpress/performance-lab` | Sprint lead | One-row per Lab plugin in readiness doc, adopter or `TODO #{id}` |
| **Host/benchmark** (TTFB LS vs PHP file, Brotli) | Per perf PR | `curl -I` + `ab -n 100 -c 10` on `nileshportfolio.duckdns.org` (OLS 1.9.1) + staging Nginx, `PERFORMANCE.md` lab | Perf owner | TTFB table footnote in `PERFORMANCE.md` + PR description log |
| **Packagist** | Weekly Monday `08:00 UTC` cron | `packagist.org/packages/{pkg}` via `webfetch` | CI `daily-audit.yml` | `docs/wordpress-7x-readiness.md:2` lock/latest table |

### 2.2 Weekly research ritual (30 min, every Monday 10:00 IST)

1. Run `scripts/research-competitors.sh` (see §2.3) — writes `reports/research-YYYY-MM-DD.md` with 8 plugin tiles (price, page cache, object cache, RUM, CSS method, bloat count, CDN, perf claim).
2. Diff against `competitive-audit-2026.md:2` — if matrix cell flips, open issue `competitor-matrix` with label `research` + update matrix via PR (1-line per cell, `@since NEXT` not needed for doc).
3. Triage new competitor release notes for `WOW` feature → score via §1.1 → if Score ≥4.0, open `enhancement` issue with `N-candidate` label + proposal branch.

### 2.3 Competitor lab (on this host or `test-lab/` clone)

- Keep `test-lab/` as throwaway WP install per `AGENTS.md` (ignored). Install one competitor at a time (`wp plugin install --activate`), capture settings screenshot + `curl -I` headers + `view-source` snippet + `wp option get wppo_settings` diff, then `wp plugin deactivate --uninstall` + `rm -rf wp-content/cache`. No permanent install.
- For LS-exclusive features, gate via `$_SERVER['SERVER_SOFTWARE']=LiteSpeed` stub in PHPUnit (as in `tests/php/ServerRulesTest.php` + `LiteSpeedIntegrationTest.php`), not by requiring LS host.

### 2.4 Decision gate

Research PR needs **reviewer confidence ≥90%** (not 95% merge gate) to flip matrix — one reviewer checks 2 independent sources (e.g. `wordpress.org/plugins` + `blog vendor`). Novel feature candidate needs Score ≥4.0 + design note in `docs/DOUBTS.md` before entering Sprint backlog.

---

## 3. Architecture & Guardrails

- **Data model:** No new tables. All new toggles live in single option `wppo_settings` (`includes/class-main.php:get_default_settings()`) with safe defaults `false`/`auto`. New top-level keys: `ai_adaptive`, `od_integration`, `llms_txt`, `edge_cache`, `perf_translations` — each behind its own tab (REST allowlist `includes/class-rest.php:423`).
- **PHP gates:** Every new WP API behind `function_exists()`/`class_exists()`/`method_exists()` per `AGENTS.md:152`. `WP_HTML_Processor`/`WP_Block_Processor` (6.9), `wp_get_loading_optimization_attributes` (7.0), `wp_is_client_side_media_processing_enabled` (7.1), `wp_set_secret` (7.2 proposal).
- **Multisite:** All new transients via `Util::transient_key()` (`includes/class-util.php:` `transient_key()`); domain-isolated file names already multisite-safe.
- **Filters:** All new extension points `wppo_*` prefix, documented in `docs/hooks.md` (e.g. `wppo_llms_txt_content`, `wppo_od_should_optimize`, `wppo_ai_adaptive_ttl`).
- **Build:** Still `@wordpress/scripts` defaults, entry points `src/index.js` + `src/lazyload.js` (+ optional `src/rum.js` already). Commit `build/` after `npm run build` per `AGENTS.md:200`.
- **Vendor:** Zero new Composer deps for Sprint 0-4 (reuse `voku/html-min`, `matthiasmullie/minify`, `woocommerce/action-scheduler`). Any new dep needs lock update + `composer dev-setup` verification.

---

## 4. Timeline Overview (Sprints → Releases)

```
2026-08-28..29  Sprint 0  Audit Green           ████  S  → v1.9.1 (patch)
2026-08-30..09-06 Sprint 1 Foundation          ████████ M  → v1.10.0 (minor)
2026-09-07..09-18 Sprint 2 Parity+CSS          ████████ M  → v1.11.0
2026-09-19..10-02 Sprint 3 N8+N5 quick wins   ██████████ M  → v1.12.0
2026-10-03..10-24 Sprint 4 N1+N2 flagship L   ██████████████ L  → v1.13.0
2026-10-25..12-08 Backlog + 7.2 prep           ░░░░░░  —  (no code, docs + stub)
2026-12-09  WP 7.2 GA  Secrets migration stub  ███  S  → v1.13.1 or v1.14.0
```

S= <1 day, M=1-3 days solo-dev (incl. tests+lint+build), L=~1 week. Each sprint is **independently shippable**; a sprint can be tagged even if next sprint slips.

---

## 5. Sprint 0 — Audit Green (2026-08-28..29) — MUST SHIP FIRST

Goal: CI green on `master` before any new feature lands. No new surface — only fixes+docs.

| ID | Task | Files | Acceptance |
|---|---|---|---|
| GH-722 | Fix `InlineCssTest` 3 errors | `tests/php/InlineCssTest.php:397,419,473` + `includes/class-litespeed-integration.php:253` chain | Add `Brain\Monkey\Functions\when('get_option')->justReturn([])` (or `when('get_option')->alias('__return_empty_array')`) in `setUp` of those 3 tests; `composer test` 380/380 green |
| LS-902a | Sync matrices | `COMPETITIVE_GAP_ANALYSIS.md:2`, `PERFORMANCE.md`, `docs/competitive-audit-2026.md:2` | Flip RUM/CDN/bg-lazy rows ✅, add TTFB host table (LS ~90ms vs PHP 170-350ms), footnotes FlyingPress 5.6 / NitroPack 2026-01-19 |
| LS-902b | Hooks audit | `docs/hooks.md` | Ensure `wppo_litespeed_*` (mode, purge_sync, nextgen, brotli, ttl) listed `@since NEXT` |
| — | `npm run lint:js` warning triage | `src/components/Dashboard.js:122` `react-hooks/exhaustive-deps` | Either wrap `cacheSettings` in `useMemo` or add `// eslint-disable-next-line` with justification; 0 warnings target |

**PR:** `fix: audit green — InlineCss mocks + matrix sync (GH-722/LS-902)` (1 PR, 1 day). **Merge gate:** `composer test` + `npm test` + `composer lint` + `npm run lint:js` + `npm run build` + `build/` committed. **Close:** #722, #706.

---

## 6. Sprint 1 — Foundation: WP Core + Library Bump (2026-08-30..09-06) — v1.10.0

Goal: Pay WP tech debt + unlock 6.9-7.1 headroom without new UI churn.

| ID | Task | Effort | Files | Acceptance |
|---|---|---|---|---|
| LS-904a | Bump `woocommerce/action-scheduler` 3.9.3→4.1.0 | S | `composer.json`, `composer.lock` | `composer update woocommerce/action-scheduler --with-dependencies` ; `composer test` green; action `wppo_convert_image_background` + `wppo_pagespeed_scan` still schedules; note in `docs/wordpress-7x-readiness.md:2` |
| LS-904b | Salted-cache family completeness | M | `templates/object-cache.php`, `tests/php/ObjectCacheTest.php` (extend) | Implement `wp_cache_get_multiple_salted()`, `set_multiple_salted()`, `delete_multiple_salted()`, `flush_group_salted()` behind `function_exists`; add `*-queries` eviction on 6.9 `flush`; Brain Monkey `*sald*` filter test |
| LS-904c | `wp_get_loading_optimization_attributes()` | M | `includes/class-image-optimisation.php`, `src/lib/lazyload-helpers.js`, `tests/php/ImageOptimisationTest.php` | For occluded/below-fold images, `fetchpriority=low/auto` via `wp_get_loading_optimization_attributes(['tag_name'=>'img','fetchpriority'=>'low'])` when `function_exists`; fallback to existing `loading=lazy` |
| LS-904d | Emoji footer module | S | `includes/class-core-tweaks.php`, `tests/php/CoreTweaksTest.php` | When `function_exists('wp_dequeue_script_module')` and `disableEmojis`, call `wp_dequeue_script_module('emoji')` + keep head dequeues; test both paths |
| LS-904e | `npm` lock refresh | S | `package-lock.json` | `npm audit` clean, `npm run build` diff only if dependency bump warrants |

**PR split:** PR-A library bump (LS-904a), PR-B salted+emoji+occluded (LS-904b-d). **Close:** #708 (partial, keep open for 2-phase). **Docs:** update `docs/wordpress-7x-readiness.md:3` Action Plan P0→done.

---

## 7. Sprint 2 — Parity: Docs + CSS Intelligence (2026-09-07..09-18) — v1.11.0

Goal: Harvest “free” Tier-2 parity wins — small code, high user-visible value.

| ID | Task | Effort | Depends | Acceptance |
|---|---|---|---|---|
| Tier-2-06 | Brotli precompression already by LS-403 — expand generic Brotli helper | S | `docs/hooks.md` | Document `enableBrotli` in readme; ensure Nginx `.gz` vs `.br` precedence noted |
| Tier-2-10 | `module_dependencies` (7.0) — classic→module combine guard | S | `includes/class-cache.php:combine_css()`, `includes/class-main.php:minify_*` | When `wp_script_modules()` present, exclude handles with `module_dependencies` from combine/minify; add data provider `@todo #module_deps` |
| Tier-2-11 | `.mo→php` starter (Perf Lab parity, N7 phase 1) | M | `includes/class-perf-translations.php` (new) + `templates/perf-translations.php` | Toggle compiles `.mo` to `.php` via `load_textdomain` filter when `function_exists('wp_cache_get_salted')`; gated `perf_translations.enabled` false default; multisite per-locale file under `wp-content/cache/wppo/lang/` |
| Tier-2-08 | Bloat toggle delta w/ Perfmatters gap | S | `includes/class-core-tweaks.php`, `src/components/PluginSetting.js` | Add 3 toggles missed: `disableRSD`, `disableWLW`, `disableSelfPingbacks` behind existing `core_tweaks` group; SPA `CheckboxOption` + `useNotice()` |
| — | Design chooser pick (#709) — if chosen, implement one of `designs/` variants | M | `src/App.js`, `src/components/common/*`, `src/styles/*` | One PR, one variant, `npm run build` + screenshot in PR, `@since NEXT` not needed (style) |

**Close:** #708 remainder + #709 when picked. **Research:** Run competitor lab for `.mo→php` baseline (no plugin does it inside a cache plugin — confirm uniqueness per `competitive-audit-2026.md:4`).

---

## 8. Sprint 3 — Novel Edge A: LLMs.txt + OD (2026-09-19..10-02) — v1.12.0

Goal: Ship 2 S/M white-space features that give sales + core-alignment narrative. No ML yet.

### 8.1 N8 LLMs.txt (S, sales, Score 5.0)

| Step | Detail | Files |
|---|---|---|
| Spec | Auto-generate `/llms.txt` + `/llms-full.txt` + chunked top-URL digest (from `wppo_web_vitals_trends` high-value URLs + sitemap), `Link: <llms.txt>; rel="alternate"` header + `<link rel="alternate" type="text/markdown">` in head. | `includes/class-llms.php` (new), `includes/class-cron.php` (schedule daily), `includes/class-main.php:includes()` |
| Settings | `llms_txt.enabled` false, `llms_txt.source = trends|sitemap|both`, `llms_txt.path = /llms.txt` (filterable). REST `update_settings tab=llms_txt`. | `includes/class-rest.php:423` allowlist |
| SPA | `src/components/LlmsPanel.js` (new card) or section in `Dashboard.js` + `SystemInfo` expose. `litespeed` pattern reuse for `FeatureCard` + `NoticeBanner`. | `src/components/Dashboard.js`, `src/lib/apiRequest.js` |
| Perf | Static file `wp-content/cache/wppo/llms.txt` + rewrite to `/llms.txt` via `add_rewrite_rule` + `template_redirect` fallback; `304` + `ETag`; gated `wppo_llms_txt_content` filter. | `includes/class-llms.php` |
| Test | Jest `LlmsPanel.test.js` + PHP `LlmsTest.php` (rewrite rule exists, file generated, `Link` header). | `tests/php/LlmsTest.php` |

*Why now:* Zero perf plugin does it (`competitive-audit-2026.md:4` N8, `COMPETITIVE_GAP_ANALYSIS.md:18`). Hostinger only vendor. 2026 SEO for AI crawlers.

### 8.2 N5 OD Integration (M, core alignment, Score 4.6)

| Step | Detail |
|---|---|
| Spec | When `class_exists('OD_URL_Metric')` or `function_exists('od_get_url_metrics')` (Lab 6.9), consume viewport groups (mobile/desktop LCP tag) to set `fetchpriority=high` for LCP image + lazy threshold `excludeFirstImages` from measured data, else degrade to heuristic 1-3. |
| Files | `includes/class-image-optimisation.php:maybe_serve_next_gen_images()`, new `includes/class-od-bridge.php`, `src/lib/lazyload-helpers.js` |
| Gate | `wppo_od_enabled` auto true when OD active, false else; filter `wppo_od_should_optimize`. No OD dep — pure `class_exists`. |
| Test | Brain Monkey stub `OD_URL_Metric`, PHPUnit data provider for OD present/absent path. |

**PRs:** `feat: LLMs.txt generation (N8)` (PR-1), `feat: Optimization Detective bridge (N5)` (PR-2) — independently shippable.

---

## 9. Sprint 4 — Novel Edge B: AI Adaptive + Edge Cache (2026-10-03..10-24) — v1.13.0 (flagship L)

Goal: Moat features — no free plugin closes RUM→auto-tune; host-agnostic edge is unique (`competitive-audit-2026.md:4`).

### 9.1 N1 AI Adaptive (L, Score 3.8 — flagship)

- **Ingest:** RUM beacon already (`includes/class-rum.php`, `src/rum.js` `web-vitals.js`) → per-breakpoint aggregation in `wppo_web_vitals_rum`. Extend to per-URL pattern ML (simple logistic over `wppo_web_vitals_trends` + `wppo_settings`).
- **Learn:** WP 7.0 AI Client `wp-ai-client` when `function_exists('wp_ai_client')` else fallback heuristic (exclude least-used scripts per `wppo_disabled_scripts` frequency + speculation eagerness). Tables: `wppo_ai_model` option (serialized, `autoload=no`).
- **Act:** Auto-generate `file_optimisation.excludeJS/CSS`, `preload_settings.exclusion` suggestions via `class-suggestion-engine.php` + one-click Apply (existing suggestion flow).
- **Speculation:** Use `wp_get_speculation_rules_configuration` (6.8) with AI-learned `prefetch` top-2 predicted next URLs (session referrer + `web_vitals_trends` heatmap).
- **Guard:** Never auto-enables — always suggestion → user confirm; feature toggle `ai_adaptive.enabled` false default, `wppo_ai_adaptive_enabled` filter.
- **Files:** `includes/class-ai-adaptive.php` (new), `includes/class-suggestion-engine.php` (extend), `src/components/AiPanel.js`, `templates/ai-model.php` optional.

### 9.2 N2 Edge HTML Adapter (L, Score 3.4 — host-agnostic)

- **Adapter:** Deploy `cache/wppo/{domain}/{path}/index.html` semantics to Cloudflare Workers / Bunny Edge via `wrangler.toml`/`bunny-edge.js` generator (stale-while-revalidate, `wppo_after_cache_clear` → `CDN_Purger` extension already has CF+Bunny `PURGE`).
- **Purge:** Reuse `LiteSpeed_Integration::sync_purge_*` pattern — add `Edge_Purger::purge_all()` alongside `CDN_Purger`.
- **TTFB target:** <30ms global (edge), vs LS-local 90ms. Bunny's 2025-06-13 roadmap claims edge HTML but not shipped — window per `competitive-audit-2026.md:1` source.
- **Files:** `includes/class-edge-cache.php` (new), `templates/cloudflare-worker.js`, `src/components/EdgeCachePanel.js`.

Both N1+N2 gated, `Util::transient_key()` locks, docs in `docs/hooks.md`.

---

## 10. Backlog — Tier 3 + WP 7.2 Secrets (after 7.2 GA 2026-12-09)

| Item | When | Note |
|---|---|---|
| REST/Feed cache, per-URL TTL, Early Hints 103 (N3), View Transitions (N4, WP 7.2 ticket #64471) | After Sprint 4, scored P2 | Keep behind `wppo_*` filter, per `COMPETITIVE_GAP_ANALYSIS.md:13-20` Tier 3 |
| Secrets API migration (`WPPO_CLOUDFLARE_API_TOKEN` / `wppo_settings` plaintext → `wp_set_secret('wppo/cloudflare-token')`) | WP 7.2 GA (proposal 2026-08-25) | Doc now in `docs/wordpress-7x-readiness.md:1` §7.2, code behind `function_exists('wp_set_secret')`, no auto-migrate — `wp_import_option_as_secret()` with rotation flag, 2-version slot |
| Symfony `^8.0` widening | Post 7.2 | `composer.json` `symfony/css-selector ^7.4 || ^8.0` (needs PHP 8.2+) |
| VC guard (Patchstack CVE feed, N9) + Volatility TTL (N10) | Q1 2027 | Keep as quarterly OKR per `competitive-audit-2026.md:4` |

---

## 11. Execution Workflow (branch → PR → CI → merge → tag)

All per `AGENTS.md:29` + `AGENTS.md:13` Release.

```
git checkout -b fix/gh-{issue}              # or feat/n8-llms
# edit includes/*.php + src/*.js + tests/php/*Test.php + src/**/__tests__/*.test.js
composer dev-setup && npm install            # if lock changed
npm run lint:js        # 0 errors, warnings triaged
composer lint          # 0 errors
npm test               # jest  jsdom, 341→? green
composer test          # phpunit + Brain Monkey, 380→? green (no missing mocks)
npm run build          # commits build/index.js + style-index.css + *.asset.php
git add build/ && git commit -m "feat: ..."
gh pr create --fill --label enhancement --reviewer @coderabbitai
# CI: webpack.yml + psalm-wpcs-check.yml + daily-audit.yml
# AI reviewer needs confidence ≥95% + all checks green to merge per .agents/skills/wppo-reviewer/SKILL.md
# Merge via tri-merge-cycle.yml (every 3 days) or manual `gh pr merge --squash`
# Tag: v* triggers release.yml → scripts/build-release.sh → WP.org SVN
```

**Hotfix rule:** `GH-722` class bug → `fix/` branch can merge without waiting 3-day cycle once audit shows green.

---

## 12. GitHub Issue/Project Tracking

| Issue | Title | Sprint | Lifecycle |
|---|---|---|---|
| #722 | [Audit] Daily verification failed | Sprint 0 | Close on green |
| #706 | LS-902 Sync matrices + TTFB disclaimer | Sprint 0 | Close on docs PR merge |
| #708 | LS-904 WP 7.x readiness + library bump | Sprint 1 + 2 | Split PR-A (lib) / PR-B (salted etc.), close when both merged |
| #707 | LS-903 N-features N1-N10 | Sprint 3-4 | Keep open as epic; create child issues `N8-LLMs`, `N5-OD`, `N1-AI`, `N2-Edge`, `N6-bfcache`, `N7-mo-php` with labels `N-feature` + `effort:S/M/L` |
| #709 | Design chooser | Sprint 2 | Pick 1 variant → implement + close |
| New | `N8-LLMs.txt`, `N5-OD`, `N1-AI-Adaptive`, `N2-Edge` | Sprint 3-4 | Create per §9-10 with checklist from this doc |
| All PRs | — | — | Label `litespeed` (done), `wp-core` (Sprint 1-2), `novel` (Sprint 3-4), `research` (matrix flips) |

**Board:** Existing labels `enhancement` + `audit` + `research`. Track Sprint 0-1 as Milestone `v1.10.0`, Sprint 2 `v1.11.0`, Sprint 3 `v1.12.0`, Sprint 4 `v1.13.0`. `tri-merge-cycle.yml` bumps `performance-optimisation.php` version + `readme.txt` Stable tag.

---

## 13. Testing Strategy

### 13.1 PHP unit (`composer test`, Brain Monkey, `phpunit.xml.dist`)

| New test file | Covers |
|---|---|
| `tests/php/LlmsTest.php` | Rewrite rule, file gen, `Link` header, `wppo_llms_txt_content` filter |
| `tests/php/OdBridgeTest.php` | OD present vs absent, `fetchpriority` fallback, `wppo_od_should_optimize` |
| `tests/php/AiAdaptiveTest.php` | RUM aggregation, suggestion generation, `ai_adaptive.enabled` gate |
| `tests/php/EdgeCacheTest.php` | Worker template rendering, purge bridge, `Util::transient_key` lock |
| Extend `ServerRulesTest`, `LiteSpeedIntegrationTest`, `ObjectCacheTest`, `CoreTweaksTest` | LS-904b-d branches |

Pattern: `Brain\Monkey\Functions\when('get_option')->justReturn(...)`, `Filters\expectAdded()`, `ReflectionMethod` for private methods per `AGENTS.md:152`.

### 13.2 JS unit (`npm test`, `@testing-library/react`, `jsdom`, `src/setupTests.js` mocks)

| New test file | Covers |
|---|---|
| `src/lib/__tests__/llms.test.js` | Pure `getLlmsContent()` helper |
| `src/components/__tests__/LlmsPanel.test.js` | Mode dropdown + `useNotice()` + `StatusBadge` |
| `src/components/__tests__/OdPanel.test.js` | OD badge + heuristic fallback |

Keep `wppoSettings` per-test extension (`apiUrl`, `nonce`, `settings.llms_txt`).

### 13.3 Manual/integration

- OLS 1.9.1 host: `curl -I` headers per mode already in `docs/performance-report-2026-08-27.md:5`; add `curl https://example.com/llms.txt` + `curl -I | grep -i Link` + view-source `link rel=alternate`.
- `ab -n 100` TTFB log per perf PR description.
- Multisite smoke: `wp_site_list` + `switch_to_blog()` transient isolation check.

---

## 14. Risks & Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| `GH-722` blocks merges (audit red) | High | Sprint 0 first; fix mocks before any feat PR |
| AI-Adaptive auto-corruption (wrong exclude) | High | Never auto-enable — suggestion + Apply only; filter `wppo_ai_adaptive_enabled`; E2E `view-source` check for `wppo-src` absence when disabled |
| Edge Worker drift (CF API break) | Medium | Template pinned to `workers-sdk` major, `wrangler.toml` versioned, fallback to origin cache (no edge = file cache) |
| OD Lab breaking change | Medium | `class_exists` guard, degrade to heuristic `class-image-optimisation.php` |
| `action-scheduler 4.1` breaking cron args | Medium | `composer test` covers `Schedule::` + `as_schedule_single_action` arg shape; staging smoke `wp cron event list` |
| TTFB claim overstatement recurrence | Low | Host-dependent table per `performance-report-2026-08-27.md:6`, `PERFORMANCE.md` disclaimer, CI comment artifact with real numbers |

---

## 15. Definition of Done (per PR/phase)

Copied from `docs/litespeed-roadmap.md:4` — extended for novel features:

- [ ] New symbols `@since NEXT`, new settings default-safe (`auto`/`false`) gated by `is_litespeed()` / `class_exists` so non-LS host unchanged.
- [ ] New transients via `Util::transient_key()` + domain-isolated files; multisite tested.
- [ ] New filters `wppo_*` documented in `docs/hooks.md` + `docs/DOUBTS.md` updated if open question.
- [ ] PHP unit + JS unit tests added; `composer test` + `npm test` + `composer lint` + `npm run lint:js` + `npm run build` green; `build/` committed.
- [ ] Manual `curl -I` / `view-source` / `ab` proof logged in PR.
- [ ] `AGENTS.md` + `docs/*` + `COMPETITIVE_GAP_ANALYSIS.md` updated if file list/matrix changed.
- [ ] AI reviewer confidence ≥95% + human sign-off before merge.

---

## 16. Open Questions (resolve before Sprint 3 code)

1. **N1 training ground:** On-device TF-Lite WASM vs PHP heuristic phase 1 — decide before spec (proposal: ship heuristic first, add WASM behind `wppo_ai_adaptive_wasm` filter post-beta).
2. **Edge provider priority:** Cloudflare Workers vs Bunny Edge first — vote (proposal: CF first (55% WP sites on CF), Bunny second via same adapter).
3. **LLMs.txt chunking:** Top-N URL count + markdown size cap — propose 50 URLs / 20KB per chunk from `wppo_web_vitals_trends`.
4. **Release naming:** Keep `NEXT` placeholder flow per `scripts/build-release.sh` — confirm, never guess `2.0.0` vs `1.10.0`.

---

*Next: Create child issues `N8`, `N5`, `N1`, `N2` from §8-9 checklists, then Sprint 0 PR `fix/gh-722-audit-green` (see §5). Keep `docs/performance-report-2026-08-27.md` as the stable competitive source — update it (not this plan) when new competitor behaviour is landed.*
