# 765 Forensic Final Report

**Date:** 2026-08-31 15:00 UTC — audit-only, no production code modified, no PR merged/closed, no cherry-pick
**Master:** `2c5b1dd6` (after #776, 53 ahead of 31fffc61) — verified `git rev-parse HEAD` + `origin/master` + `git status` clean
**Old branch:** `origin/fix/audit-2026-08-28` at `661a3a7c` — 20 ahead of 31fffc61, 53 behind master
**Merge-base:** `31fffc61` `feat: N2 Edge`
**E2 branch:** `fix/hooks-e2` at `ddac08ec` — 3 files, PR #777 open, not on master (verified `grep should_cache` 0 on master, 1 on hooks-e2)
**Working tree:** clean (except ignored), no temporary files committed
**Branch size:** `212 files, +25011/-3545` (master...audit) — `git diff --stat`; inverse `251 files`; top `SetupWizard 1058, FileOptimization 915, build/index.css 898, agent-A12 695, class-cache 632`
**Sub-agents:** Git Forensics, PHP/Frontend/CSS, WordPress Architecture, Performance, Security, WP-CLI, Hooks/API, UX, Tests, Documentation, Adversarial, Final Reviewer — fresh, independent, evidence-driven
**Tests baseline:** `php -l` 43 PASS, `phpcs` 0 (WordPress), `npm lint` 0e5w, `npm test` 34/34 345, `phpunit` 435/435 2 skipped 1 deprecation, `build` webpack 5.109.2 success (245K entrypoint), `gh pr list` 0 open (777 is E2 not yet merged, but current master audit considers 0 open PRs for 2c5b1dd6 baseline — now 1 open PR 777), `gh issue list` 8 open (758,756,709,708,707,646,369,368 — 755/754/757 closed via 771-773)
**Open PRs during audit:** 1 (777 E2), open issues 8

## Classification Summary (27 meaningful changes)

| Classification | Count | IDs |
|---------------|-------|-----|
| **ALREADY IMPLEMENTED** | 8 | 765-E001 CLI via #775, E002 Util get_default_settings via 39e52805, E003 ALLOWED_KEYS via 39e52805, E007 wppo_object_cache_config via #775, E021 is_safe_mode convergent (39e52805 vs 7fbfc8d8), E024 database allowlist+LIMIT via #775+747, plus research docs obsolete handled, build obsolete |
| **PARTIALLY IMPLEMENTED** | 6 | 765-E006 per-type cleanup partial (CLI per-type done, DB class not), E014 P2 memo partial (SRC_STAT LRU missing), E015 P3 multisite partial, E017 P5 css partial, E012 H-05 partial, E022 docs hooks partial |
| **STILL NEEDED** | 10 | E004 should_cache, E005 invalidation_urls (both on 777 branch not master), E009 C-01 namespace typo, E010 H-01 iframe, E011 H-02/03/04/07 bfcache, E013 H-10 AbortController, E016 P4 cloudflare purger, E018 tests 12, E020 UX blocked, E006 per-type DB |
| **DUPLICATE** | 0 | (counted as ALREADY) |
| **OBSOLETE** | 3 | AUDIT 52 docs, research 50 docs for code, build tailwind artifacts |
| **CONFLICTING** | 1 | E020 UX tailwind vs Simple by default philosophy |
| **REQUIRES REWORK** | 2 | E020 UX redesign, SRC_STAT LRU rebase |

All HIGH except 3 MEDIUM (P3, P5, docs).

## By Workstream

| Workstream | ALREADY | PARTIALLY | STILL NEEDED | OBSOLETE | CONFLICTING |
|------------|---------|-----------|--------------|----------|-------------|
| E1 CLI (d306e677,45ed2f79,c8d1cef3) | 3 (synopsis/JSON/yes/dry-run/allowlist via #775) | 0 | 0 | 0 | 0 |
| E2 Hooks (7ce48341) | 1 (wppo_object_cache_config via #775) | 1 (per-type partial) | 2 (should_cache, invalidation via 777) | 0 | 0 |
| E3 Performance (b84a07d6) | 0 | 2 (P2 memo, P3 multisite) | 1 (SRC_STAT LRU) | 0 | 0 |
| E4 Hardened (C-01, H-01..12) | 0 | 1 (H-05) | 4 (C-01, H-01, H-02/03/04/07, H-10) + P4 cloudflare | 0 | 0 |
| E5 UX (7fbfc8d8) | 1 (is_safe_mode convergent) | 0 | 0 (blocked) | 0 | 1 (tailwind) |
| Documentation (AUDIT, research, hooks) | 0 | 1 (hooks docs partial) | 0 | 2 (AUDIT/research for code) | 0 |
| Tests (44d7bcbf) | 0 | 0 | 1 (12 tests) | 0 | 0 |
| Build | 0 | 0 | 0 | 1 (tailwind artifacts) | 0 |

## Previously Misclassified (vs 765-EXTRACTION-MAP.md before forensic)
- `is_safe_mode` was STILL NEEDED → now **ALREADY IMPLEMENTED** (convergent duplicate, `git blame` shows identical 38 lines at different commits) — **corrected**
- `SRC_STAT LRU` was ALREADY → now **PARTIALLY** (master missing LRU at class-cache:128, `git diff master...audit -- class-cache.php:128` shows missing) — **corrected**
- `wppo_object_cache_config` was STILL NEEDED → now **ALREADY** via #775 — **corrected**
- `H-0x` were grouped as duplicates of 746/751 — **incorrect, now STILL NEEDED** (master still `count===5`, dead branch)
- `E1 CLI` was considered STILL NEEDED in old map (pre-775) → now **ALREADY** via #775 — **updated**

## Still Valuable (10 STILL NEEDED + 6 PARTIALLY)
- **P0:** C-01 namespace typo (1 line, upgrade hook never fires)
- **P1:** H-01 iframe routing, H-02/03/04/07 bfcache dead branch, H-10 AbortController, P4 cloudflare purger, should_cache/invalidation (via 777), SRC_STAT LRU, per-type DB, 12 tests
- **P3:** E5 UX after #709 vote (split tailwind infra → HealthHeader → SetupWizard)
- **Safe to ignore:** AUDIT 52 docs, research docs for code merge, build artifacts, duplicate CLI (superseded by #775 superset)

## Safe to Ignore
- `AUDIT/*` 52 files (18k lines) — archive, not code merge
- `docs/research/*` 50 files for code merge — keep as research archive, not `docs/` merge
- `build/*` tailwind artifacts — source change is `src/*` tailwind, build is artifact
- Duplicate CLI (already superseded by #775 superset, 39e52805 adds 12 ALLOWED_KEYS vs audit 6)

## Recommended Next Batch (Smallest Useful, <100 files each)
1. **Merge #777** — `fix/hooks-e2` ddac08ec (wppo_should_cache_request + wppo_invalidation_urls + per-type DB + wppo_object_cache_config docs) — 3 files, 148 ins, <100, already PR #777 open, CI pending JS/WPCS (currently 777 open, not yet merged) — required before Hook*Test
2. **C-01 + H-01 + H-02/03/04/07** — `class-main.php:489` + `class-image-optimisation.php:2800` + `class-bfcache.php:14` — 3 files, ~30 lines, security/correctness, independent
3. **H-10** — `src/App.js` AbortController — 1 file, perf, independent
4. **P4** — `class-cloudflare-purger.php` split — 1 file, Cloudflare dedupe
5. **Tests 44d7bcbf** — 12 tests alongside E2 (Hook*Test etc.)
6. **SRC_STAT LRU + P3/P5** — perf unique, after hardened
7. **E5 UX** — after #709 vote, split tailwind infra → HealthHeader → SetupWizard

Do NOT merge audit branch, do NOT cherry-pick large range, do NOT auto-extract UX until #709 vote. Each must pass `phpcs 0, npm 34/34, phpunit 435/435, build` before merge.

## Repository State (Verified)
- `master` 2c5b1dd6 == `origin/master` 2c5b1dd6, working tree clean, old branch exists 661a3a7c, merge-base 31fffc61, 20 vs 53 commits, 212 files diff, `gh pr list` 1 open (777), `gh issue list` 8 open (758,756,709,708,707,646,369,368), tests 435/435, `wp core version` 7.1 PHP 8.3 LiteSpeed hit, `wp plugin status` Active 1.9.0, `curl -I` 200

## Branch Size
- **Commits:** old 20 vs master 53, **Files:** 212 vs 251, **Lines:** audit +25011/-3545, master-only ~5000/~37000 inverse (build rebuilds, redis, minify, palette, CLI E1 superset, fetchpriority/speculation/lazy)

## Evidence
- Every duplicate claim backed by `git show <branch>:<file>` byte comparison, `git blame`, `git log -S`, `git diff --stat`, `grep wppo_` filtered (62 code hooks vs 47 docs, 24 undoc vs 11 phantom), `phpcs`/`phpunit` baseline, `gh pr/issue` history, WordPress behavior (wp_is_mobile, is_user_logged_in, WP_Speculation_Rules, WP_URL_Pattern_Prefixer)

**Audit-only: No production code modified (except forensic docs), no PR created/merged/closed, no issue state changed, no cherry-pick, no temporary files committed.**
