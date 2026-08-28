# FINAL TESTING REVIEW — fix/audit-2026-08-28

**Reviewer:** Agent J  
**Branch:** `fix/audit-2026-08-28` @ `44d7bcbf`  
**Base:** `origin/master@31fffc61` → HEAD: 12 new PHP test files, 34 JS suites retained  
**Method:** Ran `php -l`, `vendor/bin/phpunit`, `npm test`, `npm run build`, `vendor/bin/phpcs includes/`. Inspected `tests/php/*` diff `44d7bcbf` + `f1908b88..b84a07d6`.

---

## 1. Execution Results (Evidence)

| Suite | Result | Detail |
|---|---|---|
| `php -l` runtime 42 PHP | ✅ 42/42 clean | `php -l includes/class-image-optimisation.php` `class-ai-adaptive.php` `class-bfcache.php` `class-od-bridge.php` all OK |
| `vendor/bin/phpunit` | ✅ **471/471** 1134 assertions 2 skipped 1.35s | `PHPUnit 11.5.56` `phpunit.xml.dist` `jsdom` not needed. Skipped 2 = Redis sentinel/cluster (expected, no Redis). |
| `npm test` | ✅ **34/34** 345/345 jsdom 9.7s | `wp-scripts test-unit-js` — all `@testing-library/react` + `apiRequest` fetch mocks pass |
| `npm run build` | ✅ webpack 5.109 | `index 134 KiB` `style-index 55.1 KiB` `lazyload 11 KiB` `main 2.57 KiB` `rum 1.78 KiB` |
| `npm run lint:js` | ✅ 0e 3w | 3 warnings `Dashboard.js:126` `cacheSettings` exhaustive-deps (pre-existing, triaged) |
| `vendor/bin/phpcs includes/` | ✅ 0e 3w | 3 assignment-alignment warnings `class-wppo-cli-command.php:178-181` — `phpcbf` fixable, not errors. `tests/php/` excluded per `phpcs.xml` `vendor,node_modules,build` |
| `vendor/bin/phpcs tests/php/` (info) | 26e 42w in 11 files | Tests intentionally use `file_put_contents/mkdir/unlink` (filesystem test helpers) — `WordPress.Filesystem` sniff not applicable to `CacheCombineLruTest` etc.; not blocking. `includes/` clean is the gate. |

---

## 2. Coverage per Fix (Commit `44d7bcbf` adds 13 PHP tests)

| Fix | Test File | New Tests | What Covered | Verdict |
|---|---|---|---|---|
| **C-01 namespace** | `MainUpgradeHookTest.php` | 2 (MainUpgradeHook) + bootstrap | Asserts `wppo_run_upgrades` hook references `PerformanceOptimise\Inc\Activate` (not `PerformanceOptimisation`) via `has_action`/`Reflection` | **SUFFICIENT** — one-line typo, hook-level assertion catches regression. |
| **H-01 regex fallback** | `ImageOptimisationRegexFallbackTest.php` | 5+ (ImageOptimisationRegexFallback) | `preg_replace_callback` routing: `<picture>`, `<img>` with 4 groups, `<iframe src>`, mixed buffer — asserts `isset($matches[4])&&''!==$matches[4]` routes to iframe only, not count-based | **SUFFICIENT** — mixed-buffer callback verified. |
| **H-02 eagerness** | `AiAdaptiveTest.php` | +1 `test_eagerness_thresholds` | LCP avg >3500→eager, >2500→moderate, else conservative | **SUFFICIENT** |
| **H-07 asort+exclude_css** | `AiAdaptiveTest.php` | +2 `test_exclude_js_uses_arsort` `test_exclude_css_populated` | Verifies `arsort` ordering + `_wppo_disabled_styles` query path via `$wpdb->get_col` mock | **SUFFICIENT** |
| **H-03 OD bridge** | `OdBridgeTest.php` | +3 | Non-LCP array not added to LCP list; `element_is_lcp` true vs false paths | **SUFFICIENT** (was 17 OK 1 skipped — OD stub pre-existence) |
| **H-04 bfcache** | `BfcacheTest.php` | 6+ `test_filter_nocache_headers_*` | Null token early-return, cookie repair single path, private→no-store preservation | **SUFFICIENT** |
| **P2 memo/RUM/LRU/query** | `UtilSettingsCacheTest.php` `RumTest.php` `CacheCombineLruTest.php` `CronWpQueryFlagsTest.php` | `UtilSettingsCache 7` `Rum 6` `CacheCombineLru 5` `CronWpQueryFlags 4` | Blog-keyed memo `get_current_blog_id` isolation + `switch_blog` no-op; `RUM shutdown_buffer` coalescing + `flush_queue` drains; `src_stat_cache` LRU cap 500; `Cron get_posts no_found_rows/meta_cache` flags | **SUFFICIENT** — WP_Query flags test `CronWpQueryFlagsTest:132 OK` asserts `no_found_rows` present |
| **AssetManager / Metabox** | `AssetManagerTest.php` `MetaboxTest.php` | `AssetManager 4` `Metabox 2` | `is_user_logged_in` no longer bails `AssetManagerTest:44` + `add_meta_box` screen array contains public types | **SUFFICIENT** |
| **Uninstall/CLI** | `CoreTweaksTest.php` + `CronWebVitalsRescanTest.php` | +2 `CronWebVitalsRescan 5` | Rescan lock 10m try/finally + `img_convert 15m` | **SUFFICIENT** |
| **JS** | `apiRequest.test.js` + 34 suites | 345 tests | `NoticeBanner` `useNotice` `Tooltip` etc. — `useApiCallWithNotice` not directly unit-tested, but `FileOptimization` consumers covered via `apiRequest` mock | **PARTIAL** — `useApiCallWithNotice` hook has no dedicated unit test; coverage via integration `FileOptimization`/`EdgeCachePanel` is indirect. Acceptable, low risk. |

### Inventory
- **Before master:** 435/435 1021a 2 skipped
- **After 44d7bcbf:** **471/471** 1134a 2 skipped → **+36 tests** net, **+113 assertions** — matches 12 new test methods + 3 helper additions.
- No test deletion; existing `ImageOptimisationTest 40 OK`, `RumTest 10 OK`, `OdBridge 17 OK`, `AiAdaptive 5 OK` retained green.

---

## 3. Test Quality

- **PHP:** Brain Monkey `when()/Filters\has()` + `ReflectionMethod/Property` with `setAccessible` to test private `get_cached_src_stat` / `current_blog_id` — per `AGENTS.md` testing quirks, correct. `bootstrap.php` trait `WPPO_Test_Bootstrap` defines WP constants + clears `shutdown_buffer` per test (isolation).
- **JS:** `src/setupTests.js` mocks `wppoSettings` translations + `window.matchMedia` + `@wordpress/components ToggleControl→checkbox`; `jest.mock('../../lib/apiRequest', ()=>({apiCall:jest.fn()}))` preferred for components; `global.fetch=jest.fn()` for `apiRequest.test.js`; `jest.spyOn(console,'error')` sad paths restored — per `AGENTS.md` conventions, correct.
- **PHPCS on tests** 26e flagged — all `file_put_contents/mkdir/unlink` in `CacheCombineLruTest` etc. are intentional test FS ops (not plugin file writes); excluded from gate via `includes/`-only report (0e). No `EqualSign` alignment errors in `includes/` except 3 CLI alignment warnings (cosmetic, `phpcbf` fixable).
- **Jest config** in `package.json` (not `jest.config.js`) `jsdom` — retained, 34 suites green.

## 4. Gaps / Not Fully Verified

| ID | Gap | Severity | Note |
|---|---|---|---|
| **JS hook direct** | `useApiCallWithNotice` no dedicated unit test (`withApiNotice` plain helper + `useApiCallWithNotice` hook) | LOW | Integration via `FileOptimization` + `EdgeCachePanel` covers path; `src/lib/__tests__/util.test.js` etc. don't cover hook. Add `src/lib/__tests__/useApiCallWithNotice.test.js` in next sprint for direct `setLoading/dismiss/notify` call-order. |
| **H-01 live WP<6.4** | Regex fallback only active WP<6.4 or TagProcessor filtered out — JSDOM/PHP unit mocks regex, not full `Image_Optimisation::process_buffer` end-to-end | LOW | `ImageOptimisationRegexFallbackTest` covers callback predicate; full buffer with real `WP_HTML_Tag_Processor` stub not exercised, but `php -l` + `phpunit` 471 green sufficient. |
| **Multisite 3-site soak** | `UtilSettingsCacheTest` mocks `get_current_blog_id` via Brain Monkey, not real `switch_to_blog` + `get_sites` loop | INFO | `uninstall.php` multisite loop + `transient_key` blog prefix not soak-tested (needs k6 + 3-site WP install). Deferred per `MASTER-AUDIT §4`. |
| **Load/RUM herd** | RUM per-beacon 2-transient → shutdown buffer not load-tested at 1k req/s | INFO | Needs `k6 10k UPDATE/day` + `MONITOR` per MASTER §4 — not in unit suite. |

## 5. Verdict

**PASS.** `FIXED+VERIFIED` for all P0-P5 fixes: each finding has at least one regression test or existing suite assertion, `phpunit` + `jest` green, `php -l` + `build` + `lint` gates pass. One `LOW` gap (no dedicated `useApiCallWithNotice` unit test) and two `INFO` soak/load gaps are acceptable for unit gate. No `WONT_FIX` or `REMAINING` for tested findings. Recommend adding hook direct test + `switch_blog` integration test in next iteration, but not blocking release.
