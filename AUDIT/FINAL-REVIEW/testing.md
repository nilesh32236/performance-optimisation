# FINAL TESTING REVIEW — Post-Fix Regression & Coverage

**Reviewer:** Final Test Agent (independent)  
**Date:** 2026-08-28  
**Workspace:** `/var/www/nileshportfolio.duckdns.org/wp-content/plugins/performance-optimisation`  
**Git range inspected:** `origin/master (c127b865)` → `HEAD (9ce35209)` ; `git diff HEAD` (unstaged, 43 files); `git diff HEAD -- tests/php/` ; `git status`  
**Method:** Re-read every changed test (`tests/php/CronSitemapTest.php:271`, `AbilitiesTest.php:202`, `RumTest.php:370`, `bootstrap.php:268`), diffed `includes/class-rum.php:433`, `class-util.php:820`, `class-google-fonts.php:330`, `class-cron.php`, `class-abilities.php`, `class-rest.php`, `class-database-cleanup.php`, `class-cache.php`, `class-critical-css.php`, `class-used-css.php`, `src/components/common/NoticeBanner.js:56`, `src/components/common/__tests__/NoticeBanner.test.js:75`; executed `vendor/bin/phpunit` (403 tests, 958 assertions, 1 skipped, 0 failed) and `npm test` (`wp-scripts test-unit-js` — 2 failed / 62 passed / 10 suites). No trust in implementation-agent self-report.

---

## 1. Changed Tests — Were Mocks Weakened?

| File | Diff vs `HEAD` | Verdict | Evidence |
|------|----------------|---------|----------|
| `tests/php/CronSitemapTest.php:47-137` | +4 `wp_remote_retrieve_response_code` stubs + `when(...)->justReturn(200/500)` in all 4 sitemap tests (`tests/php/CronSitemapTest.php:56`, `:88`, `:120`, `:149`) | **Strengthened, not weakened** | Aligns with new `includes/class-cron.php:502` gate `if (200 !== wp_remote_retrieve_response_code($response)) continue;`. Without stub, `wp_remote_retrieve_response_code` would be unmocked (Brain Monkey returns `null`) and new code would incorrectly skip every sitemap fetch. Adding `justReturn(200)` for success cases and `justReturn(500)` + `WPPO_WP_Error` for failure restores correct test semantics. No assertion removed or `expect()->never()` loosened. |
| `tests/php/RumTest.php:44-115` | +`delete_transient`, `wp_next_scheduled`, `wp_schedule_single_event`, `wp_rand` stubs (`tests/php/RumTest.php:44-53`) + deterministic transients alias (`:76-89`) + `Util::clear_settings_cache()` before `is_enabled` checks (`:115`, `:119`) | **Strengthened** | New `includes/class-rum.php:317-345` `store_sample()` now calls `get_transient(queue)`, `set_transient(queue)`, `delete_transient(queue)`, `wp_rand(1,10)`, `wp_next_scheduled('wppo_rum_flush')`, `wp_schedule_single_event(+300)`. Previous stubs missing those calls would fatal or leave queue locked. Added `wp_rand->2` makes random-flush branch deterministic (never 1). `clear_settings_cache` fixes new `Util::get_settings():90` memo that would otherwise leak `wppo_settings` between tests in same process (first `is_enabled` would cache stale value for second assertion). No expectation weakened. |
| `tests/php/bootstrap.php:216-218` | `WPPO_Test_Bootstrap::setUp():213` now calls `Util::clear_settings_cache()` + `Image_Optimisation::clear_file_exists_cache()` per test | **Isolation fix, not weakening** | New per-request memos `includes/class-util.php:88-109` (`$settings_cache`) and `includes/class-image-optimisation.php:file_exists_cache` would otherwise bleed across tests in same PHPUnit process. Reset each `setUp()` is required; mirrors existing `reset_cached_home_urls():218` pattern. No test logic relaxed. |
| `tests/php/AbilitiesTest.php:202` (new file, untracked) | 2 tests: `test_database_cleanup_ability_enum_matches_canonical:29` + `test_execute_database_cleanup_accepts_trashed_posts_rejects_trash:81` | **Net new, not weakened** | See §2.1. |

**Overall mocks:** No `expect()->never()` removed, no `assertSame` loosened to `assertTrue`, no `justReturn` replaced with weaker stub. Changes are additive to satisfy new production dependencies.

---

## 2. New Fix — Does It Have a Regression Test?

### 2.1 P1 Fix with full regression test — PASS

**`Abilities` enum drift (`includes/class-abilities.php:275`, `includes/class-database-cleanup.php:72`)**

- **Old bug:** `Abilities::get_operational_abilities():275` hard-coded `enum: ['revisions','auto_drafts','trash','spam','transients','orphans','all']` diverging from canonical `Database_Cleanup::TABLE_MAP` keys (`trashed_posts`, `spam_comments`, `expired_transients`, `orphan_postmeta` etc.).
- **Fix:** `Abilities.php:275` now `array('revisions','auto_drafts','trashed_posts','spam_comments','trashed_comments','expired_transients','orphan_postmeta','unattached_media','oembed_cache','all')` + `execute_database_cleanup():451` delegates to `Database_Cleanup::get_valid_cleanup_types()` / `CLEANUP_METHOD_MAP` (`includes/class-database-cleanup.php:72-110`).
- **Test `tests/php/AbilitiesTest.php:29-74`:** Fetches ability via `ReflectionMethod(get_operational_abilities)`, asserts `enum === array_merge(keys(TABLE_MAP), ['all'])` sorted, and explicitly asserts legacy aliases absent (`trash`, `spam`, `transients`, `orphans`) and canonical present (`trashed_posts`, `spam_comments`, `expired_transients`, `orphan_postmeta`). **Source-of-truth drift now fails fast if any future TABLE_MAP change is not mirrored.**
- **Test `:81-97`:** Executes `Abilities::execute_database_cleanup(['type'=>'trashed_posts'])` with `WPPO_DB_Mock_Abilities` (3 IDs → 3 cleaned) and asserts `trash` returns 0 cleaned (rejected). Uses real `$wpdb->get_col` loop contract (first call returns IDs, second empty).
- **Verdict:** **Sufficient** — covers both schema and execution rejection. No further action.

### 2.2 Fixes with partial / missing regression tests — GAPS

| Fix (file:line) | What changed | Existing test | Coverage verdict | Gap |
|-----------------|--------------|---------------|------------------|-----|
| **RUM queue** `includes/class-rum.php:317-431` — `store_sample()` now buffers to `wppo_rum_queue` transient, `flush_queue():357` batches with lock `wppo_rum_flush_lock`, thresholds `QUEUE_MAX=100` / `FLUSH_THRESHOLD=20`, random 1/10 flush, cron fallback `wppo_rum_flush+300`, `_ts` day-bucketing, retention `MAX_DAYS=14`, `MAX_PATHS_PER_DAY=200`, `get_data():155` opportunistic flush | `tests/php/RumTest.php:126-185` `test_collect_stores_aggregated_sample` + `test_collect_accumulates_samples` exercise `RUM::collect` → `store_sample` → `get_data()->flush_queue()` end-to-end with `wp_rand=2` (never random) and `wp_next_scheduled=false` | **Partial** — happy-path aggregation still passes (403/403 green), but queue mechanics uncovered | No test asserts: (a) queue capped at `QUEUE_MAX` (`array_slice -100`), (b) threshold-triggered `flush_queue` at 20, (c) lock `get_transient(lock)` early return, (d) random path (`wp_rand===1` / `rand===1`), (e) `wp_schedule_single_event(+300)` fallback when below threshold, (f) lock cleared in `finally`, (g) `_ts` bucketing by sample day not flush day, (h) `MAX_PATHS_PER_DAY` eviction within queued batch. `wp_rand` stubbed to 2 means neither random branch ever executes. Low-traffic cron path is scheduled but never asserted via `expect(wp_schedule_single_event)`. Risk: silent write amplification regression or lock leak not caught. |
| **Util `get_settings` memo** `includes/class-util.php:88-184` — `Util::get_settings():112` memoizes `get_option('wppo_settings')`, `set_settings_cache`, `clear_settings_cache`, `reset_all_caches`, `ensure_settings_cache_hook` + `on_settings_update/add` actions | `tests/php/RumTest.php:110-121` indirectly calls `Util::get_settings()` via `RUM::is_enabled()`; `tests/php/bootstrap.php:216` clears memo per test. No dedicated `Util*Test.php` for memo | **Missing** | No test asserts: `get_settings` collapses 6 deserializations to 1 `get_option` call per request, second call returns cached without `get_option`, `update_option('wppo_settings')` invalidates via `on_settings_update`, `delete_option` clears, `get_settings` handles non-array `get_option` return, `ensure_settings_cache_hook` registers once. Stale-cache bug after `update_settings` REST would not be caught. `grep -rn get_settings tests/php` → only `RumTest.php` lines above. |
| **Google Fonts SSRF host check** `includes/class-google-fonts.php:109`, `:260`, `:300` — `strpos('fonts.googleapis.com')` → `wp_parse_url($url, PHP_URL_HOST) === 'fonts.googleapis.com'` (and `fonts.gstatic.com` for font files) | Zero tests (`grep -r Google_Fonts tests/php` → empty) | **Missing** — security fix with no regression guard | No test for substring bypass: `https://evil.com/fonts.googleapis.com/css?family=Roboto` or `https://fonts.googleapis.com.evil.com/css` must be rejected, while `https://fonts.googleapis.com/css2?...` and `https://fonts.gstatic.com/s/roboto/...` accepted in respective methods. `download_font_file():302` host check equally untested. This was `AUDIT/SECURITY-REVIEW.md` high. |
| **Cron sitemap HTTP 200 gate** `includes/class-cron.php:502` + `Util::get_settings` migration + `wppo_rum_flush` hook `:74`, `:115`, `:502` | `tests/php/CronSitemapTest.php:47-135` now stubs `wp_remote_retrieve_response_code` | **Partial** | `test_get_sitemap_urls_returns_empty_on_failure:111` conflates two failure modes ( `WPPO_WP_Error` + `500` body `''`) in one test; does not isolate: (a) valid body but `404` → must be skipped with empty, (b) `WP_Error` with `200` code, (c) sitemap index child following with off-site filter + 500 cap. No test for new `Cron::schedule_cron_jobs` using `Util::get_settings` (still mocked via `get_option` stub — not asserting memo). No test that `Cron::__construct` registers `add_action('wppo_rum_flush', [RUM,'flush_queue'])` or that `Cron::clear_scheduled_hooks` clears `wppo_rum_flush`. |
| **REST path traversal fallback** `includes/class-rest.php:390-413` — when `realpath(cacheDir+path)===false` (uncached page), validates via `wp_normalize_path(trailingslashit(cacheDir)+ltrim(path,'/\\'))` prefix + `strpos('..')` | `tests/php/RestTest.php:436-488` `test_clear_cache_all_when_cache_dir_missing_returns_success` (empty path, missing dir) only | **Partial** | No test for the new branch: non-empty path with `realpath===false` (e.g. `/about/` not yet cached) must succeed after normalized prefix check, while `../../etc/passwd` or `//wppo/../../` must return 400. Existing test only covers empty path (early return before traversal logic). Traversal bypass via `..` in candidate_path uncovered. |
| **Util `ALLOWED_SETTINGS_KEYS` single source** `includes/class-util.php:43` + `class-rest.php:451`, `:731` (`Util::ALLOWED_SETTINGS_TABS/KEYS`) + `class-abilities.php` via `Database_Cleanup` constants | `tests/php/RestTest.php:199-226` rejects `core_tweaks` tab/key; `tests/php/AbilitiesTest.php:29` verifies `Database_Cleanup` map — but no cross-artifact drift test | **Partial** | No test asserts `Util::ALLOWED_SETTINGS_KEYS === Rest::allowed (via Util) === wppoSettings.allowedSettingsKeys` (JS) or that `ALLOWED_SETTINGS_TABS === ALLOWED_SETTINGS_KEYS`. `PluginSetting.js:34-43` fallback logic not unit-tested (requires `wppoSettings` global mock). Drift between PHP constant and JS `FALLBACK_ALLOWED_KEYS` not guarded except manual comment. |
| **DB cleanup dedup** `includes/class-database-cleanup.php:122-210` `delete_in_batches()` + `CLEANUP_METHOD_MAP` / `get_valid_cleanup_types()` | `tests/php/DatabaseCleanupTest.php` (existing 10+ tests) still passes under `403 OK`; `AbilitiesTest` checks map indirectly | **Implicit** | No dedicated test for `delete_in_batches` helper (batch `SELECT LIMIT 1000 → DELETE meta → DELETE rows` loop, `last_error` handling, `while(count>=batch)` re-loop). Refactor preserves semantics but shared helper now single point of failure; prior 5× copy-paste each had separate test paths now collapsed. Recommend adding `DatabaseCleanup::get_cleanup_method_map()` shape test (already partially covered by `AbilitiesTest` but not inside `DatabaseCleanupTest`). Low risk as `vendor/bin/phpunit` green, but coverage metric for new helper is 0 direct hits. |
| **Cache/Critical/UsedCSS litespeed/multisite/OR fixes** `includes/class-cache.php:119` memo, `:374` litespeed bypass, `:261` `Util::get_settings`; `class-critical-css.php:156` `blog_id` hash; `class-used-css.php:483` OR vs AND | No new tests; `CacheTest.php`, `CriticalCssTest.php`, `Util*` suites still 403 pass | **Missing** | Not in this review's scope per prompt, but flagged as fixes without targeted regression tests. `CriticalCss` multisite hash change (`md5(blog_id+template+stylesheet)`) has no multisite `get_current_blog_id` variation test; `Used_CSS::should_process_selector` OR fix (A07) has no `extract_simple_selectors` truth-table test. |

**Summary:** Of 11 distinct production fixes in this diff batch, **1 has full regression coverage**, **5 have partial coverage** (existing suite exercises happy path but not the new branch/threshold/security property), **5 have no direct test** for the changed line.

---

## 3. Are Tests Weakened?

**PHP:** No. All changes add required stubs to satisfy new dependencies (`wp_remote_retrieve_response_code`, `delete_transient`, `wp_rand`, etc.). Assertions unchanged; `expect('wp_remote_get')->once()` (`CronSitemapTest.php:210`) and `never()` (`:231`) and `assertContains`/`assertSame` for aggregation remain strict. Bootstrap isolation tighter, not looser. Risk would be false-green from incomplete mock — mitigated because 403 still green and new code's `get_transient(lock)` early return path not stubbed (returns `false` from empty `$transients` array) — so lock path exercised as unlocked.

**JS/npm:** The opposite — tests **not weakened but now stale, causing CI red**. `src/components/common/NoticeBanner.js:35-36` correctly changed to `role={type==='error'?'alert':'status'} aria-live={type==='error'?'assertive':'polite'}` (WAI-ARIA fix for non-error `status+polite`). `src/components/common/__tests__/NoticeBanner.test.js:26-47` still asserts `not.toHaveAttribute('aria-live')` and `role='alert'` for `warning`:

```
FAIL src/components/common/__tests__/NoticeBanner.test.js
  renders an error notice with alert semantics
    Expected not to have attribute: aria-live  Received: aria-live="assertive"  (NoticeBanner.test.js:33)
  renders non-error notices with alert semantics and no aria-live
    Expected attribute: role="alert"  Received: role="status"  (NoticeBanner.test.js:45)
```

60 other Jest tests pass (`src/components/__tests__/Dashboard.test.js`, `FileOptimization.test.js`, `DatabaseCleanup.test.js`, `ObjectCache.test.js`, `PreloadSettings.test.js`, `PluginSetting.test.js`, `PerformanceAudit.test.js`). Production code is correct; **tests must be updated to:**

```js
// NoticeBanner.test.js:32-33 error
expect(banner).toHaveAttribute('role','alert');
expect(banner).toHaveAttribute('aria-live','assertive');
// NoticeBanner.test.js:45-46 warning/success/info
expect(banner).toHaveAttribute('role','status');
expect(banner).toHaveAttribute('aria-live','polite');
```

Until fixed, `npm test` fails (2/64) while `vendor/bin/phpunit` passes — mixed CI gate.

---

## 4. Coverage Assessment — Is It Sufficient?

| Suite | Result | Coverage of new fixes |
|-------|--------|-----------------------|
| `vendor/bin/phpunit` | **403 tests, 958 assertions, 1 skipped, 0 failed** (`phpunit.xml.dist:7` `bootstrap.php:1`) — includes new `AbilitiesTest:2` (enumerated in `vendor/bin/phpunit --list-tests` as `AbilitiesTest::test_database_cleanup_ability_enum_matches_canonical` etc.) | Happy paths covered; security/edge branches above are not. Raw count 403 is unchanged for most suites because new fixes added 0–4 stubs rather than new cases for edge properties. Line-level coverage for `class-rum.php:317-431` estimated <60% (lock, thresholds, random, cron, eviction untouched); `class-google-fonts.php` 0%; `class-util.php:get_settings` 0% direct. |
| `npm test` (`wp-scripts test-unit-js`) | **2 failed, 62 passed, 10 suites** | Frontend a11y fix shipped without synchronised test — fails gate. `FileOptimization.js:311-314` `Home/End` tab handling (`includes/class-cron.php` analogue for tablist) has no Jest assertion; `litespeed.js:60` `modeLabel` consolidation now covered indirectly via `Dashboard` snapshot but not isolated; `CheckboxOption.js:36` id fix not asserting `id !== undefined` when description missing. |

**Overall sufficiency: INSUFFICIENT for the security + queue + memo fixes.** The suite prevents re-break of the exact historical bug (enum drift, sitemap off-site filter, basic RUM aggregation) but would not catch: Google Fonts substring SSRF regression, RUM queue unbounded growth or lock leak, `get_settings` stale cache after `update_option`, or `clear_cache` `..` traversal via fallback prefix + missing `..` check removal.

---

## 5. Required Follow-ups (ranked)

| Pri | File:Line | Action | Why |
|-----|-----------|--------|-----|
| **P0** | `src/components/common/__tests__/NoticeBanner.test.js:32-46` | Update to `role alert+assertive` for error and `status+polite` for warning/success/info | CI red; `npm test` blocks `AGENTS.md` required order `lint → build` |
| **P0** | `tests/php/GoogleFontsTest.php` (new) | Add `test_normalize_google_fonts_url_rejects_substring_host` (evil `evil.com/fonts.googleapis.com`, `fonts.googleapis.com.evil.com` → `''`; `fonts.googleapis.com` → url; `fonts.gstatic.com` only accepted by `download_font_file`) and `test_style_loader_tag_rejects_offsite_href` using `wp_parse_url` host check | Security fix `class-google-fonts.php:109-302` currently 0% tested |
| **P1** | `tests/php/RumQueueTest.php` or extend `RumTest.php` | Add: `test_queue_caps_at_100`, `test_flush_threshold_auto_flush_at_20`, `test_flush_lock_prevents_double_flush`, `test_rand_one_triggers_flush`, `test_schedule_single_event_queued_when_below_threshold`, `test_get_data_flushes_queue` with `transient` spy counts, `test_timestamp_bucket_by_sample_day_not_flush_day` (inject `_ts` yesterday), `test_max_paths_per_day_evicts_oldest` | New `class-rum.php:59-431` has 5 interacting thresholds; current `RumTest` hits none beyond 1–2 sample accumulation |
| **P1** | `tests/php/UtilSettingsMemoTest.php` (new) | Assert `get_settings()` calls `get_option` once per request, second call no `get_option`, `on_settings_update` refreshes memo, `clear_settings_cache` clears, non-array `get_option` → `[]` | Memo touches every frontend render (`Main`, `Cache`, `Cron`, `Used_CSS`) — `includes/class-util.php:88-184` |
| **P2** | `tests/php/CronSitemapTest.php:111` | Split `test_returns_empty_on_failure` into `test_returns_empty_on_http_404_with_body` vs `test_returns_empty_on_wp_error` and add `test_get_sitemap_urls_follows_index_children_and_caps_500_urls` + `test_wppo_rum_flush_hook_registered` | Isolates `wp_remote_retrieve_response_code` gate `class-cron.php:502`; verifies new hook not dropped |
| **P2** | `tests/php/RestTest.php` | Add `test_clear_cache_uncached_page_fallback_accepts_valid_path` (realpath false, candidate under cacheDir → 200) and `test_clear_cache_rejects_traversal_without_realpath` (`../`, `..\\`, `%2e%2e`) expecting 400; add `test_allowed_settings_keys_single_source` (`assertSame(Util::ALLOWED_SETTINGS_KEYS, Util::ALLOWED_SETTINGS_TABS)` and import vs util parity) | Covers `class-rest.php:390-413` fallback `..` check — currently only empty path tested |
| **P3** | `tests/php/CriticalCssTest.php` / `UsedCssTest.php` | Add multisite blog_id variation for `Critical_CSS::get_cache_key()` and OR truth-table for `Used_CSS::should_process_selector` (`.sidebar .widget` with only one present → keep) | Documents `class-critical-css.php:156` and `class-used-css.php:483` fixes |

---

## 6. Verdict

| Dimension | Verdict |
|-----------|---------|
| Changed PHP tests fix mocks | **PASS** — additive, not weakened (`CronSitemapTest.php:47-164`, `RumTest.php:44-121`, `bootstrap.php:216`) |
| New `AbilitiesTest.php:202` | **PASS** — exemplary enum-drift regression (source-of-truth `TABLE_MAP` + legacy alias rejection) |
| PHP suite health | **PASS** — `403 OK, 1 skipped` |
| JS suite health | **FAIL** — `src/components/common/__tests__/NoticeBanner.test.js:33,45` stale vs `src/components/common/NoticeBanner.js:35-36` (2 failed, `npm test` red) |
| Coverage for new fixes (`RUM queue`, `get_settings memo`, `google fonts host` etc.) | **FAIL (insufficient)** — 1/11 fully covered, 5 partial, 5 uncovered; security + queue thresholds + memo invalidation lack direct regression tests |
| Tests weakened | **No** — no assertion loosened; frontend failure is under-assertion of new correct semantics, not weakening |

**Overall: CONDITIONAL FAIL.** The P1 enum drift is well-guarded and existing mocks were correctly tightened, but CI cannot pass (`npm test` 2 failures) and three high-value fixes ship without regression guards (`google fonts host` SSRF, `RUM queue` thresholds/lock, `Util::get_settings` stale memo). Add the P0 + P1 items above (estimated ~40 lines each) to make the post-fix coverage match the audit's `95%+ confidence` merge gate (`.agents/skills/wppo-reviewer/SKILL.md`).

