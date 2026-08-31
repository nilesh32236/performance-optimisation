# 765 Forensic Audit — Comprehensive Comparison

**Date:** 2026-08-31 15:00 UTC (audit-only, no production code modified)
**Master:** `2c5b1dd6` (after #776, 53 commits ahead of 31fffc61) — verified `git rev-parse HEAD` + `origin/master`
**Old branch:** `origin/fix/audit-2026-08-28` at `661a3a7c` — 20 commits ahead of `31fffc61`, 53 behind master
**Merge-base:** `31fffc61` `feat: N2 Edge HTML Cache Adapter`
**Diff:** `212 files, +25011/-3545` (master...audit) vs `251 files, ~5000/~37000` inverse
**Working tree:** clean (except ignored), `origin/fix/hooks-e2` at `ddac08ec` not on master
**Branch logs:** `git log --oneline master..audit` 20 commits (fd830190→661a3a7c), `audit..master` 53 commits (744,747,748,753,761,746,751,749,767,771,772,773,775 etc.)
**Top diff:** `SetupWizard.js 1058, FileOptimization.js 915, build/index.css 898, agent-A12 695, agent-A06 643, class-cache 632, HOOK-AUDIT 616, WPPO-CLI 323, class-util 264`

## Methodology
- `git diff --stat/--name-status`, `git show <branch>:<file>`, `git blame <branch> -- <file>`, `git log -S <string> --all`, `git log -G`, `grep wppo_` filtered vs docs, `vendor/bin/phpcs`, `npm test`, `phpunit`
- Fresh sub-agents: Git Forensics, PHP/Frontend/CSS, WordPress Architecture, Performance, Security, WP-CLI, Hooks, UX, Tests, Documentation, Adversarial, Final Reviewer
- Functional equivalence test: "If old change disappeared forever, would master lose meaningful functionality?"

## Findings by Workstream

### E1 CLI — 3 commits (d306e677 Phase1, 45ed2f79 Phase2, c8d1cef3 converge)
- **ALREADY IMPLEMENTED** via **PR #775 (39e52805, 7eb05beb)** — 39e52805 adds synopsis `[<action>]`, `--format=json` JSON-only, `--yes` tty+confirm, `--dry-run` would_delete, allowlist `TABLE_MAP`, 9 types (`trashed_comments` etc.), `Util::get_default_settings`, `ALLOWED_KEYS 12`. `git log -S "--dry-run"` → both, `git blame master -- class-wppo-cli-command.php:203` → 39e52805, `git diff master...audit -- class-wppo-cli-command.php` still shows delta but same feature. Evolved superset (master adds 12 vs audit 6 keys, 9 types vs 6). **HIGH**

### E2 Hooks — 7ce48341 Phase3 PR-C
- `wppo_should_cache_request` (Cache:1538) — **STILL NEEDED** — master grep 0, only on `fix/hooks-e2` ddac08ec. Not duplicate. **HIGH**
- `wppo_invalidation_urls` (Cache:1934) — **STILL NEEDED** — same. **HIGH**
- `wppo_database_cleanup_completed` per-type (DatabaseCleanup:737) — **PARTIALLY IMPLEMENTED** — master has `all` at 774 + CLI per-type at 388 (39e52805), but not in `class-database-cleanup.php` per-type (will be in ddac08ec). **HIGH**
- `wppo_object_cache_config` (ObjectCache:50) — **ALREADY IMPLEMENTED** via #775 (ALLOWED_KEYS + get_redis_config filter at 230) — `grep` master shows 3 hits. **HIGH**
- Lazy FS etc. — **STILL NEEDED** minor. **MEDIUM**

### E3 Performance — b84a07d6 P2
- `Util::get_settings` memo — **PARTIALLY** — master has memo via b2425ed2/39e52805 but not `SRC_STAT_CACHE_LIMIT LRU 500` at class-cache:128 (audit adds 24 lines, master missing). **HIGH**
- `Cache LRU`, `no_found_rows`, `RUM shutdown`, cron locks 5→15m — **PARTIALLY** — cron locks already via 751 (15m), but SRC_STAT LRU + RUM buffer not in master. **HIGH**

### E4 Hardened/H-0x — 6 commits
- **C-01** namespace typo `PerformanceOptimisation→PerformanceOptimise` (6a39cb49:489) — **STILL NEEDED** — master still bug at 489. **HIGH**
- **H-01** iframe `isset($matches[4])` (426b0e7a:2800) — **STILL NEEDED** — master still `count===5`. **HIGH**
- **H-02/03/04/07** eager threshold, arsort+css, OD else, bfcache dead branch (1f86e245) — **STILL NEEDED** — master still dead branch. **HIGH**
- **H-05/06/08/12** logged-in dequeue etc. (856032d1) — **PARTIALLY** — master has different guards via 746/751 but Bunny + Vary not same. **MEDIUM**
- **H-10** AbortController (870590e7) — **STILL NEEDED** — master grep 0. **HIGH**
- **P3** blog-keyed memo, uninstall wildcard (d6a8163c) — **PARTIALLY** — transient isolation exists but uninstall wildcard not in master. **MEDIUM**
- **P4** Cloudflare purger + useApiCallWithNotice (5ec22efd) — **STILL NEEDED** — `class-cloudflare-purger.php` ENOENT on master. **HIGH**
- **P5** css cleanup (f1908b88) — **PARTIALLY** — WPCS 767 touched same CSS for different reason. **MEDIUM**

### E5 UX — 7fbfc8d8 + 6e35e35e + 661a3a7c
- 5 pillars, HealthHeader 3 rings, Wizard, Disclosure, tailwind — **STILL NEEDED but BLOCKED** on #709 vote — master `HealthHeader.js` ENOENT, `grep tailwind` 0, except `is_safe_mode` (see below). **CONFLICTING** if blindly ported vs `Simple by default` philosophy. **HIGH**
- `is_safe_mode` `?wppo_safe=1` 600s cookie (7fbfc8d8:989) — **ALREADY IMPLEMENTED** via **different commit** `39e52805:989` — identical 38 lines, different hash, convergent duplicate. **HIGH**

### Master-Only (Not in Audit, Would Be Lost if Audit Force-Merged)
- **744** RedisSentinel strict types (34a37999), **747** `optimize_table` preg_match+_doing_it_wrong (84b60f26), **748** metabox nonce (2ef42ac4), **753** REST sensitive DRY (c4260af2), **761** Bolt minify URL-to-path/regex (d96bc5fb), **746** Autofix UI (d2ba94e6), **751** Autofix perf (7cf84421), **749** Palette aria (3102ed1c), **767** WPCS 31 (d948d87e), **771** fetchpriority filterable (7b11fa92), **772** speculation validate (ad29d4f4), **773** lazy LCP (c1cda64f), **775** CLI E1 superset — all **NOT duplicate**, audit is stale.

### Tests — 44d7bcbf
- 12 new tests: Hook*Test, WppoCli*Test, CacheCombineLruTest, BfcacheTest etc. — **Not on master** — `ls tests/php/Hook*` master 0. **STILL NEEDED**. **HIGH**

### Docs/Build
- **AUDIT/* 52 files** — **OBSOLETE** for code merge (archive). **HIGH**
- **docs/research/* 50 files** — **OBSOLETE** for code (research archive, not merge). **HIGH**
- **build/* tailwind artifacts** — **OBSOLETE** (artifact of source, master build is split 134K + lazy, tailwind not until #709). **HIGH**
- **docs/research `PRODUCT-PHILOSOPHY.md` etc.** — **STILL NEEDED** as research but not code; philosophy on master is `readme.txt:21` only.

## Previously Misclassified (vs docs/maintenance/765-EXTRACTION-MAP.md)
- **is_safe_mode** was previously partially tracked as STILL NEEDED — now proven **ALREADY IMPLEMENTED** via convergent duplicate (39e52805 vs 7fbfc8d8) — correct to mark IMPLEMENTED, not still needed.
- **wppo_object_cache_config** was previously STILL NEEDED — now **ALREADY IMPLEMENTED** via #775 — correct.
- **SRC_STAT LRU** was previously lumped as ALREADY — now proven **PARTIALLY** (still needed unique).
- **H-0x** were previously grouped as STILL NEEDED — confirmed **STILL NEEDED** (not duplicate).

## Classification Summary (27 meaningful changes)
- **ALREADY IMPLEMENTED:** 8 (CLI synopsis/JSON/yes/dry-run/allowlist via #775, Util get_default_settings via 39e52805, ALLOWED_KEYS via 39e52805, wppo_object_cache_config via 39e52805, is_safe_mode convergent, CLI per-type hook via #775, database allowlist via #775+747, research docs obsolete handled)
- **PARTIALLY IMPLEMENTED:** 6 (Hooks per-type cleanup partial, P2 memo partial, P3 multisite partial, P5 css partial, H-05 partial, docs hooks partial)
- **STILL NEEDED:** 10 (C-01, H-01, H-02/03/04/07, H-10, P4 cloudflare, E5 UX blocked, tests 12, should_cache/invalidation hooks via 777 not yet on master but exists on branch `fix/hooks-e2`, per-type cleanup in DB class)
- **DUPLICATE:** 0 (genuine duplicate already counted as ALREADY IMPLEMENTED)
- **OBSOLETE:** 3 (AUDIT docs 52, research docs 50 for code merge, build tailwind artifacts)
- **CONFLICTING:** 1 (UX tailwind/Option B vs Simple by default)
- **REQUIRES REWORK:** 2 (E5 UX requires redesign per philosophy, SRC_STAT LRU requires rebase)

All classifications HIGH except 3 MEDIUM (P3, P5, docs).

## Safe to Ignore vs Still Valuable
- **Safe to ignore:** AUDIT docs, research docs for code merge, build artifacts, duplicate CLI (already superseded)
- **Still valuable:** 10 STILL NEEDED + 6 PARTIALLY (C-01, H-0x, AbortController, cloudflare purger, SRC_STAT LRU, tests, hooks via 777)
- **Master-only must preserve:** 13 commits (744,747,748,753,761,746,751,749,767,771,772,773,775)

## Recommended Next Batch (Smallest Useful)
1. **E2 Hooks via #777** — `wppo_should_cache_request` + `wppo_invalidation_urls` (already on `fix/hooks-e2` ddac08ec, 3 files, <100, tests pending) — merge #777 after CI (currently 777 open, CI pending JS/WPCS)
2. **E4 Hardened C-01 + H-01 + H-02/03/04/07** — namespace typo, iframe routing, bfcache dead branch (3 files, ~30 lines, security)
3. **E4 H-10 AbortController** — `src/App.js` per-request controller (1 file, perf)
4. **P4 Cloudflare purger** — `class-cloudflare-purger.php` split (1 file)
5. **Tests 44d7bcbf** — Hook*Test etc. (12 files, should accompany E2)

Do NOT create giant PR, do NOT merge audit branch, do NOT auto-extract UX until #709 vote.

## Evidence Footer
Every duplicate claim backed by `git show <branch>:<file>` byte comparison, `git blame <branch> -- <file> | grep`, `git log -S <string> --all`, `git diff --stat`, `vendor/bin/phpcs`, `phpunit 435/435`, `gh pr list` 0/1, `gh issue list` 8.
