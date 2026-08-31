# 765 Remaining Work — Survives Forensic Review

**Master:** `2c5b1dd6` → **Audit:** `661a3a7c` → **E2 branch:** `fix/hooks-e2` at `ddac08ec` (not on master, PR #777 open)
**Only lists changes that survive forensic review (10 STILL NEEDED + 6 PARTIALLY).**

## P0 — Must Fix (1)
- **C-01** `class-main.php:489` `wppo_run_upgrades` namespace typo `PerformanceOptimisation→PerformanceOptimise` — `git show master:class-main.php:489` still bug, `git log -S` only audit. **Files:** `class-main.php` 1 line. **Risk:** upgrade hook never fires. **Deps:** none. **Impl:** single `s` fix, `vendor/bin/phpcs` + `phpunit`.

## P1 — High Value Next (6)
- **H-01** `class-image-optimisation.php:2800` iframe routing `isset($matches[4])` vs `count===5` — **Files:** `class-image-optimisation.php` 1 line. **Risk:** iframe mis-routed. **Impl:** `isset` check.
- **H-02/03/04/07** eager threshold, arsort+css, OD else, bfcache dead branch `class-bfcache.php` `class-image-optimisation.php` `class-main.php` — **Files:** `class-bfcache.php` 14 lines, `class-image-optimisation.php` 10, `class-main.php` 5. **Risk:** bfcache cookie repair dead code, OD else wrong. **Deps:** none.
- **H-10** `src/App.js` AbortController per-request (870590e7) — **Files:** `src/App.js` 15 lines. **Risk:** sibling fetch abort isolation. **Impl:** add `AbortController` per `useEffect`.
- **P4** `class-cloudflare-purger.php` + `useApiCallWithNotice` (5ec22efd) — **Files:** `class-cloudflare-purger.php` (new), `src/lib/useApiCallWithNotice.js` — **Risk:** Cloudflare purge transport dedupe. **Deps:** none.
- **wppo_should_cache_request** + **wppo_invalidation_urls** — **Files:** `class-cache.php` 33+60 lines (already on `fix/hooks-e2` ddac08ec, PR #777 open) — **Risk:** extensibility, cache safety. **Deps:** none, but **777 must be merged first** (currently `ddac08ec` not on master, 0 in master, 1 in hooks-e2).
- **SRC_STAT LRU** `class-cache.php:128` LRU 500 for `core_will_inline` — **Files:** `class-cache.php` 24 lines — **Risk:** src stat `is_readable+filesize` per style without cache (500 limit). **Deps:** none.

## P2 — Useful (4)
- **Tests 44d7bcbf** — 12 tests: `Hook*Test.php` 3, `WppoCli*Test.php` 3, `CacheCombineLruTest.php`, `BfcacheTest.php`, `UtilSettingsCacheTest.php` etc. — **Files:** `tests/php/*` 12. **Risk:** regression coverage missing. **Deps:** E2 hooks (tests for hooks).
- **P3** `uninstall.php` wildcard + multisite memo `d6a8163c` — **Files:** `uninstall.php` 32, `class-util.php` already partially. **Risk:** multisite transient leakage. **Deps:** none.
- **P5** css `_lazy-placeholder.scss` etc. — **Files:** `src/css/*` 3. **Risk:** placeholder CLS. **Deps:** E5 UX? but standalone.
- **Per-type DB cleanup in class-database-cleanup.php** (7ce48341:737) — **Files:** `class-database-cleanup.php` 10 lines — **Risk:** per-type hook not in core cleanup (only CLI per-type via 39e52805). **Deps:** none (will be in 777).

## P3 — Product/Design (1, Blocked)
- **E5 UX Option B** `src/components/HealthHeader.js:283`, `SetupWizard.js:1033`, `Disclosure.js:91`, `tailwind` (7fbfc8d8,661a3a7c) — **Files:** `src/App.js`, `HealthHeader.js`, `SetupWizard.js`, `common/Disclosure`, `ui/*`, `tailwind.config.js`, `postcss.config.js`, `build/*` — **Risk:** violates `Simple by default` if blindly ported, needs redesign. **Deps:** #709 vote. **Impl:** split tailwind infra → HealthHeader → SetupWizard after vote.

## Excluded (Safe to Ignore)
- **ALREADY IMPLEMENTED:** CLI E1 (775), Util get_default_settings (775), ALLOWED_KEYS (775), is_safe_mode convergent (39e52805), wppo_object_cache_config (775), database allowlist+LIMIT (775+747)
- **OBSOLETE:** AUDIT/* 52, research docs 50 for code merge, build tailwind artifacts

## Recommended Implementation (Smallest Useful Batches)
1. **P0 C-01 + H-01** — 2 files, 2 lines, security/correctness, merge as `fix/hardened-c01-h01`
2. **H-10 AbortController + P4 cloudflare** — 2 files, separate PRs or combined E4
3. **E2 Hooks #777** — merge existing `fix/hooks-e2` ddac08ec (already 3 files, <100) — required before tests
4. **Tests 44d7bcbf** — 12 tests alongside E2
5. **SRC_STAT LRU + P3/P5** — perf, after hardened
6. **E5 UX** — after #709 vote, split

**Each <100 files, must pass `phpcs 0, npm 34/34, phpunit 435/435, build` before merge.**

## Dependencies
- E2 hooks (777) must merge before Hook*Test (44d7bcbf)
- C-01/H-01 independent, no deps
- E5 blocked on #709

## Files Summary (Remaining)
- `class-main.php` (C-01), `class-image-optimisation.php` (H-01), `class-bfcache.php` (H-02), `src/App.js` (H-10), `class-cloudflare-purger.php` (P4), `class-cache.php` (SRC_STAT LRU + already hooks via 777), `class-database-cleanup.php` (per-type), `tests/php/*` 12, `src/components/HealthHeader.js` etc. (E5 blocked)

## Risk if Not Done
- C-01 upgrade hook never fires, H-01 iframe mis-route, bfcache dead branch, no AbortController isolation, no Cloudflare dedupe, no SRC_STAT cache (500× stat per request), no tests for hooks (regression risk), UX remains old (but safe per philosophy).
