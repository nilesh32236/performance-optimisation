# 765 Extraction Map — fix/audit-2026-08-28 (212 files) vs master @ c1cda64f

**Branch:** `origin/fix/audit-2026-08-28` at `661a3a7c` (pushed 2026-08-31)
**Master:** `c1cda64f` (after 773 — includes 771/772/773: fetchpriority, speculation, lazy 757)
**Diff:** `git diff master...origin/fix/audit-2026-08-28` → 212 files changed, +25011/-3545 (filtered non-AUDIT still ~114 code files)
**Overlap with master merges:** 30+ files in `comm -12` (already touched by 746/751/761/771/772/773 etc.), ~182 only-in-765 before #755/754/757, now reduced but still largely STILL NEEDED (CLI, Hooks, H-fixes, UX)
**Decision in prior run:** CLOSED supersized, branch preserved for split (<100 files/PR). Do NOT merge as-is.

## Classification Legend
- **ALREADY IN MASTER** — change exists verbatim or superseded by merged PRs
- **STILL NEEDED** — unique, not in master, still relevant
- **PARTIALLY NEEDED** — overlapping file but 765 has additional delta beyond master
- **OBSOLETE** — superseded/duplicate of newer fix
- **CONFLICTING** — will conflict with current master, needs rebase
- **REQUIRES REWORK** — relevant but needs update for current master guard/patterns

## Summary Extraction (5 PR Split Proposal)

### PR-E1 — CLI Phases (c8d1cef3/45ed2f79/d306e677 etc.)
**Files:** `includes/class-wppo-cli-command.php` (+ `tests/php/WppoCli*`, `uninstall.php`, `docs/research/wp-cli-hooks/*` docs)
**Diff:** 60+ lines — adds `synopsis`/`format=json`/`--yes/--dry-run`/`allowlist` converge (phase inputs converge phase, i.e., synopsis, json-only format, allowlist). At `master` this is **NOT present** (master has base CLI only).
**Class:** STILL NEEDED
**Relevance:** High — WP-CLI is public surface, tests exist.
**Conflicts:** Low — touches CLI only.
**Split size:** ~8 files, <100, tests included.
**Deps:** None.

### PR-E2 — Hooks Expansion (7ce48341 Phase3 PR-C)
**Files:** `includes/class-main.php`, `includes/class-cache.php`, `includes/class-abilities.php` (Util::get_settings memo), `class-util.php`, `class-object-cache.php`, `tests/php/Hook*Test.php`, `tests/php/UtilSettingsCacheTest.php`
**Diff:** Adds `wppo_should_cache_request`, `wppo_invalidation_urls`, per-type cleanup, `wppo_object_cache_config` + lazy fs, Abilities Util memo (get_option→Util::get_settings).
**Class:** STILL NEEDED (some memo already in master via 751's Util fix? No — master has Util memo already via 751's class-cache; but hooks filters themselves not in master)
**Overlap:** `class-cache.php` already has wppo_cache_stats fix, but hooks add new `apply_filters` calls — PARTIALLY NEEDED (rebase onto current cache).
**Rework:** Ensure `@since NEXT`, `function_exists`/`has_filter` guards, `docs/hooks.md` (currently 765 adds 60 lines vs master docs/hooks.md — STILL NEEDED).

### PR-E3 — Performance P2-P5 (b84a07d6/d6a8163c/5ec22efd etc.)
**Files:** `includes/class-cache.php` (LRU, core_will_inline_memo), `includes/class-cron.php` (cursor), `includes/class-telemetry.php` (remote head removal), `src/lazyload.js`, `src/css/*`, `tests/php/CacheCombineLruTest.php` etc.
**Class:** PARTIALLY NEEDED / ALREADY IN MASTER for some
- `class-cache.php` LRU + depth guard **ALREADY IN MASTER** via 751 (atomic stats, depth>20)
- `class-cron.php` cursor **ALREADY IN MASTER** via 751 (ID>last_id)
- `class-telemetry.php` remote head **ALREADY IN MASTER** via 751 (wppo_telemetry_allow_remote_head)
- **765 adds** additional `src file stat LRU` for core_will_inline (`includes/class-cache.php:128` `src file stat`) — STILL NEEDED (unique)
- CSS `_mixins xs:400px`, `--wppo-accent` vars — **ALREADY IN MASTER** via 746 partially, but 765 adds `_lazy-placeholder.scss` etc. — PARTIALLY NEEDED
**Rework:** Diff against master to pick only unique LRU + CSS vars not yet in master.

### PR-E4 — Hardened Fixes H-01..H-12 + C-01 + P2
**Files:** `includes/class-image-optimisation.php` (regex fallback lazy-load), `includes/class-main.php` (namespace typo fix C-01), `src/App.js` (AbortController), `includes/class-cache.php` (bfcache doc), `tests/php/*` (InlineCssTest, MainUpgradeHookTest)
**Class:** MOSTLY ALREADY IN MASTER? Need per-file check: `class-main.php: C-01 namespace typo PerformanceOptimisation→PerformanceOptimise` was already in `68a2f66a audit`? Check `git diff master -- class-main.php` includes that fix? 765 diff shows C-01 not in master? Actually `68a2f66a` is in fix/audit's history but not in master (master is at 788bf59b which does NOT contain 68a2f66a — those audit commits are only on fix/audit branch). So C-01, H-01 etc. are **STILL NEEDED** on master.
**Conflicts:** Moderate — App.js HealthHeader changes conflict with current App.js (746's dashboard changes already).
**Recommendation:** Cherry-pick H-01…H-12 as single PR (≈15 files) after checking each file's current master version.

### PR-E5 — UX / Option B Redesign (7fbfc8d8 feat(ux) + tailwind)
**Files:** `src/App.js` (5 pillars, sidebar), `src/components/HealthHeader.js`, `src/components/SetupWizard.js`, `src/css/*` (tailwind), `postcss.config.js`, `tailwind.config.js`, `build/*`, `src/components/ui/*`, `docs/research/ux-redesign/*` (30 docs)
**Class:** STILL NEEDED but BLOCKED on #709
**Relevance:** Product decision — requires vote on 3 static proposals (`designs/variant-a/b/c.html`). Tailwind build artifacts (`build/index.css` etc.) conflict heavily with current master builds.
**Deps:** #709 decision, #708 LS-904, #707 N-features context.
**Split size:** Must be split further: E5a tailwind infra (postcss, tailwind.config, css vars) + E5b HealthHeader + E5c SetupWizard + docs (each <100).
**Risk:** High — `Simple by default. Powerful when needed. Safe at every step.` — Option B redesign is 260→64 collapsible premium deck, may violate simplicity. Needs PRODUCT-PHILOSOPHY.md check (currently `readme.txt:21` philosophy, no ux-redesign docs on master — 765 adds them).

## Detailed File-Level Classification (comm -12 vs comm -23)

**30 Overlap (already partially in master):**
- `.gitignore`, `build/*` (7 files), `includes/class-cache.php`, `includes/class-cron.php`, `includes/class-database-cleanup.php`, `includes/class-main.php`, `includes/class-metabox.php`, `includes/class-pagespeed.php`, `includes/class-rest.php`, `includes/class-telemetry.php`, `includes/class-used-css.php`, `src/components/Dashboard.js`, `src/components/EdgeCachePanel.js`, `src/components/FileOptimization.js`, `src/components/ImageOptimization.js`, `src/components/PluginSetting.js`, `src/css/abstracts/_mixins.scss`, `src/css/components/_card.scss`, `src/css/components/_fields.scss`, `src/css/layout/_sidebar.scss`
→ Each needs line-level `git diff master...fix/audit -- <file>` to see if 765 adds unique lines beyond merged PRs. Preliminary: most are PARTIALLY NEEDED (e.g., class-cache adds src stat LRU not in master).

**182 Only-in-765:**
- **AUDIT/* (52 files)** — OBSOLETE for code merge (docs), but valuable for history — keep as `AUDIT/` not merged to master, or merge as `docs/audit/` if needed. Recommend NOT auto-merge 52 audit docs (≈18k lines) — archive separately.
- **docs/research/ux-redesign/* (30 files) + wp-cli-hooks/* (20 files)** — STILL NEEDED docs for PRODUCT-PHILOSOPHY, but not code; move to `docs/research/` after 765 split.
- **includes/class-abilities.php, -ai-adaptive, -bfcache, -cdn-purger, -edge-cache, -llms, -od-bridge, -rum, -util, -wppo-cli-command** — STILL NEEDED (unique hooks/CLI/bfcache).
- **src/App.js, HealthHeader.js, SetupWizard.js, common/Disclosure etc., ui/*, tailwind.css** — STILL NEEDED but BLOCKED.
- **tests/php/* (15 tests)** — STILL NEEDED (AbilitiesTest, BfcacheTest, Hook*Test etc.).
- **postcss/tailwind config, package.json tailwind deps, templates/bunny-edge etc.** — STILL NEEDED for E5.

## Recommended Extraction Order
1. **E1 CLI** — independent, tests, low risk
2. **E2 Hooks** — docs/hooks.md, 3 Hook*Test, @since NEXT
3. **E4 Hardened H-fixes** — small, correctness, after E2 to avoid rebase churn
4. **E3 Perf unique** — pick src stat LRU + remaining CSS vars after E4
5. **E5 UX** — last, after #709 vote, split tailwind infra → HealthHeader → SetupWizard

Each <100 files, must pass `vendor/bin/phpcs 0, npm lint 0e, npm test 34/34, phpunit 435/435, npm run build` before merge.

## Conflicts & Rework Notes
- All E's will conflict on `build/*` — rebuild via `npm run build` after cherry-pick, keep source `src/*` as authority.
- E5 tailwind will conflict on `src/index.js`, `src/css/style.scss` — rebase carefully.
- AUDIT docs should not be merged as code — keep branch for reference, do not count toward 100-file limit.
