# Final Architecture Audit — Phase 2 Campaign (ARCH-016)

> Historical Phase 2 closeout. Phase 3 supersedes its metrics and remaining-work decisions with `ARCHITECTURE-BASELINE.md`, `ARCHITECTURE-QUALITY.md`, and schema-v2 `DEPENDENCY-GRAPH.json`.

Date: 2026-09-23. Scope: campaign section 36. Docs-only; no runtime changes.
Queue: `docs/architecture/refactor-queue.yaml` (phase2_items ARCH-001..016 + future_items).
Evidence: `class-inventory.json` (regenerated), `DEPENDENCY-GRAPH.json`, ARCHITECTURE.md,
LOAD-ORDER.md, INCLUDE-HIERARCHY.md. All 15 implementation items merged with
post-merge `wp wppo verify` 6/7 (known environmental CLI-writability warn).

## 1. BEFORE → AFTER (historical hand-count archaeology; not generator-canonical)

The values below preserve the Phase 2 closeout record and its commit-era hand counts. They are not current metrics; use the current generated artifacts and `ARCHITECTURE-BASELINE.md` for decisions.

| Metric | Before (ARCH-001) | After (ARCH-016) | Delta |
|---|---|---|---|
| Main lines / methods | 14173 / 232 | 11091 / 235 | −3082 lines / +3 methods (facades) |
| Image_Optimisation lines / methods | 10967 / 165 | 8999 / 183 | −1968 / +18 (facades) |
| Critical_CSS lines / methods | 7595 / 137 | 7595 / 137 | untouched (future work) |
| Cache lines / methods | 7305 / 137 | 5643 / 161 | −1662 / +24 (facades) |
| AI_Adaptive lines / methods | 6323 / 98 | 3989 / 76 | −2334 / −22 |
| Util lines / methods | 4987 / 155 | 5006 / 156 | +19 (1 additive redaction helper) |
| Used_CSS lines / methods | 4961 / 94 | 4961 / 94 | consolidation only (no split) |
| Rest lines / methods | 4495 / 73 | 3891 / 76 | −604 / +3 (facades) |
| includes/ layout | 57 files flat | 66 files in 14 domain dirs + root Util + minify/ | hierarchy live |
| New boundary/service classes | 0 | 9 (below) | Loader_Map, Settings_Migrations, Script_Strategy, Css_Combine, Cache_Invalidator, Lcp_Preload, Ai_Anomaly, Rest_Cache, Rest_Settings |
| Static cross-class edges | 322 | 375 | +53 (new classes + proxies; includes docblock mentions — generator limitation) |
| PHPUnit tests / assertions | 2469 / 9417 | 2654 / 11646 | +185 / +2229 |
| Jest tests | 780 | 789 | +9 (PresetsCard) |
| FileOptimization.js lines | 6201 | 6114 | −87 (1 of 14 cards) |
| Dashboard.js / App.js | 2168 / 654 | 2168 / 654 | untouched |

Method-count increases in Main/Cache/Image_Optimisation/Rest are the facade
effect (thin proxies + `@internal` bridges retained per REFACTORING-RULES
facade-first rule); every targeted class lost 15–25% of its lines and at least
one responsibility cluster. Edge-count growth likewise reflects new classes +
proxies. Phase 2 used the old raw reference approximation; Phase 3 schema-v2
token analysis now provides the executable graph in `DEPENDENCY-GRAPH.json`.

## 2. Per-item record

| Item | Change | Post-merge proof |
|---|---|---|
| ARCH-001 | Inventory generator + ARCH/LOAD/HIERARCHY docs + Phase-2 queue | docs-only; suites green |
| ARCH-002 | Canonical 14-dir tree decided (Util at root, minify/ as-is) | docs-only; 3× merge-ready reviews |
| ARCH-003 | Loader_Map owns eager/fallback/CLI paths; Main delegates | loader smoke (45 entries, autoload, CLI); front 200 |
| ARCH-004 | 17 maybe_migrate_* → Settings_Migrations; Main proxies | 89 targeted tests; verify 6/7 |
| ARCH-005 | 55 defer/delay → Script_Strategy; blog-keyed delay memo (documented correction) | 7 parity tests; live defer/delay markup intact |
| ARCH-006 | 28 combine → Css_Combine; output byte-parity | 15 parity tests; live index.css 42KB served |
| ARCH-007 | Invalidation/purge → Cache_Invalidator; actions sequenced | 11 tests; purge proven environmental-only failure |
| ARCH-008 | 74 LCP/preload → Lcp_Preload; static memos moved | 18 tests; live preloads intact |
| ARCH-009 | Exclusion fallback dedup (Used_CSS → Critical_CSS owner) | 6 parity tests; guards preserved |
| ARCH-010 | 43 anomaly → Ai_Anomaly; 7/7 bodies identical | 11 tests; AI_Adaptive 98→76 methods |
| ARCH-011 | 12 cache/settings handlers → Rest_Cache/Rest_Settings | 68 tests; 48 live routes |
| ARCH-012 | WP-Cron primitives → Scheduler; dedup semantics kept | 36 tests; 7 cron hooks live |
| ARCH-013 | 62 pure renames to 14 dirs; loader/entry/tests/docs updated | loader healthy live (200/302/48/8/8); suite 2647 green |
| ARCH-014 | 17 internal Util:: edges → boundary owners; proxies stay | 7 tests; triple-Yes reviews |
| ARCH-015 | PresetsCard extracted (dumb card + props) | Jest 789; build committed |

## 3. Architecture quality gates (§35) — campaign verdicts

- Responsibility: every new class owns one cluster (loader data, migrations,
  script strategy, CSS combine, invalidation, LCP preload, anomaly, REST
  slices). PASS.
- Ownership: single owner per moved behavior; Filesystem-vs-service splits
  documented (no double-ownership). PASS.
- Coupling: features → boundaries direction established; new Main↔service
  reference bridges (ARCH-004/005/006/007) are the documented exception —
  same-request collaboration, revisit in FUT-002. PARTIAL (tracked).
- Discovery: 14 domain dirs live; Util-at-root + minify/ exceptions recorded.
  PASS.
- Loading: single owner (Loader_Map) + smoke test; post-move live verification
  passed on every surface. PASS.
- Compatibility: zero public API removals; proxies everywhere; protected areas
  (ESI, drop-ins, uninstall, PSR-4 refusal) intact. PASS.
- Testing: +185 PHPUnit / +2229 assertions, all behavior-pinning (no
  coverage-for-coverage). Two test-hygiene rules learned mid-campaign and
  applied: separate-process isolation for leak-dangerous stubs; anonymous disk
  doubles where `method_exists` guards apply. PASS.
- Maintainability: hierarchy doc + per-class mapping rows maintained per item.
  PASS.
- Ratchet: −9.0k lines across the four decomposed god classes (3082 + 1968 + 1662 + 2334). PASS.
- Runtime: installed-WordPress verification after all 15 merges, no
  regressions (two scares — CLI purge perms, option-census drift — both
  proven environmental). PASS.

## 4. Remainder classification (§36)

Resolved: flat includes/ (now 14 dirs); Main setup_hooks (3-line delegate);
Util key/settings/filesystem/URL/woo/scheduler/http splits (Phase 1 + ARCH);
Cache combine + invalidation; migration/defer clusters; LCP/preload;
exclusion dedup; anomaly; REST cache/settings slice; cron primitives;
internal Util edges; 1 React card; loader centralization; inventory tooling.

Intentional: facade proxies + `@internal` bridges (all items; revisit
FUT-002); Util-canonical helpers (checksum/sanitize/bounds, query policy);
mixed-version `class_exists` guards; `Util` at root until callers migrate;
`minify/` untouched; memo non-blog-keying where single-blog lifecycle proven
(exclusion memo) vs blog-keyed where fixed (delay context, RUM digest).

Phase 3 queue: P3-001 establishes the tokenizer baseline and quality model.
P3-002 through P3-024 own scheduler, transport, state, cache, settings,
insight, database, edge, Main/Util, AI model, React, guardrail, and final-audit work.
The historical FUT-001..006 records remain for traceability; FUT-006 is
pending supersession by P3-001.

Protected: LiteSpeed ESI bridge + coexistence (WONTFIX #1291); manual
loading (no PSR-4); `useState`-only SPA; advanced-cache/object-cache
contracts; uninstall behavior.

Acceptable: method-count facade inflation (ratchets down with FUT-002);
edge-count growth (new classes + proxies; crude metric);
per-file static memos with reset wiring; Dashboard/App untouched (no
pressing defect).

## 5. Success criteria (§37) — self-assessment

1. Hierarchy logical + live — YES. 2. Find-by-responsibility — YES.
3. God classes progressively decomposed (4 of 8 majors; rest triaged) —
MOSTLY (FUT items for remainder). 4. Main orchestration + proxies — YES.
5. Util reduced to facade + canonical helpers — YES (external callers: FUT-002).
6.–8. Cache/Image/Critical splits — Cache YES, Image partial (LCP done, lazy/media FUT-004), Critical NO (FUT-001, untouched by design this round).
9. AI/Used/REST/Cron decomposed per evidence — YES (slices; remainders FUT).
10.–14. Loading/API/tests/CI correct — YES. 15. Per-merge WP verification —
YES (15/15). 16.–18. Queue/PR hygiene — YES (one item/issue/PR throughout;
no duplicates). 19. Easier to extend — YES (new code has obvious owners +
loader smoke + inventory tooling).

The repository is NOT fully refactored (Critical_CSS, Util externals,
React remainder are real), but every remainder is classified with an owner
and a future item. No substantial architectural problem is unrecorded.
