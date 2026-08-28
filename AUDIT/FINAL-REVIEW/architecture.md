# FINAL ARCHITECTURE REVIEW — fix/audit-2026-08-28

**Reviewer:** Agent J  
**Branch:** `fix/audit-2026-08-28` @ `44d7bcbf` | `origin/master@31fffc61` → HEAD 94 files  
**Method:** `git diff` + `Read` per file, traced class-loading, hook registration, duplication metrics, complexity delta.

---

## 1. Fixes Verified

| ID | File:Line | Finding | Verdict | Evidence | Regressions / Design Note |
|---|---|---|---|---|---|
| **C-01** | `includes/class-main.php:489` | Architecture: dead `wppo_run_upgrades` hook due to namespace typo | **FIXED+VERIFIED** | Hoes registration `add_action('wppo_run_upgrades', array('PerformanceOptimise\Inc\Activate','maybe_run_upgrades'))` now matches `includes/class-activate.php:11` `namespace PerformanceOptimise\Inc`. Single-line string fix, no indirection. | None. `Activate::maybe_run_upgrades` is idempotent cache-key eviction retry — safe to re-enable. |
| **D-19 Cloudflare purger** | `includes/class-cloudflare-purger.php:1-100` `class-cdn-purger.php:131-165` `class-edge-purger.php:132-165` | ~40-line `wp_remote_request` duplication `CDN_Purger::purge_cloudflare` vs `Edge_Purger::purge_cloudflare` (N2 introduced) | **FIXED+VERIFIED** | New `Cloudflare_Purger::purge(zone,token,logTag):bool` `class-cloudflare-purger.php:54-72` centralizes `https://api.cloudflare.com/client/v4/zones/.../purge_cache` + `Authorization: Bearer` + `purge_everything:true` + `TIMEOUT 10` + `is_wp_error` + 2xx check + `do_action('wppo_debug_log')` `class-cloudflare-purger.php:77-95`. Both callers delegate `class-cdn-purger.php:147-148` (`cloudflare`) `class-edge-purger.php:141-142` (`cloudflare-edge`) with stale-classmap fallback (`if(class_exists(...))`) | Clean extraction. `logTag` preserves filter semantics (`cloudflare` vs `cloudflare-edge`). Fallback inline `wp_remote_request` in both callers covers upgrade race where `class-cloudflare-purger.php` not yet on disk. Not over-abstracted. |
| **D-03 withNotification** | `src/lib/useApiCallWithNotice.js:1-124` `src/lib/apiWithNotice.js:1-13` `FileOptimization.js:127` `EdgeCachePanel.js:41` `ImageOptimization.js:100` | 10-line `setIsLoading→dismiss→try apiCall→notify→catch→console.error→finally` scaffold copy-pasted across 5 components | **FIXED+VERIFIED** | Canonical `withApiNotice(promiseOrFactory, {successMessage,errorMessage,notify,dismiss,setLoading,durationMs})` `useApiCallWithNotice.js:37-86` + hook `useApiCallWithNotice({notify,dismiss,setLoading})→callWithNotice` `useApiCallWithNotice.js:102-116` via `useCallback`. Accepts Promise or thunk (thunk preferred so `setLoading(true)` before request — F-06). `apiWithNotice.js` re-exports canonical `useApiCallWithNotice.js:10-13` for audit carry-over. Consumers migrated: `FileOptimization:127` `withNotification+withLiteSpeedNotice`, `EdgeCachePanel:41`, `ImageOptimization:100` (4→? call-sites thunk-wrapped) | Complexity justified. Hook stable via `useCallback[notify,dismiss,setLoading]` — deps correct, no infinite loop risk. One re-export alias is minor indirection but avoids breaking import path. |
| **P2 Util memo blog isolation** | `includes/class-util.php:87-278` | `Util::get_settings()` memo not blog-keyed → `switch_to_blog` leaks prior blog's settings (A13 F-COMPAT-03) | **FIXED+VERIFIED** | `settings_cache:array<int,array>` + `settings_cache_loaded:array<int,bool>` `class-util.php:97-105` + `current_blog_id():int` try/catch `class-util.php:122-131` + `get_settings()` ` $bid=current_blog_id(); if(!empty(loaded[bid])) return cache[bid] ` `class-util.php:145-157` + `set_settings_cache`/`on_settings_update/add` blog-keyed `class-util.php:167-278` + `switch_blog` hook `class-util.php:248` + `on_switch_blog` no-op (per-blog keying already isolates) `class-util.php:214-219` | Correct design: per-blog keying is sufficient, `switch_blog` handler intentionally no-op (safety net). `clear_settings_cache(null)` clears all (test isolation) vs `clear_settings_cache(blog_id)` single-blog. Handles `Brain\Monkey` stub mis-config via `try/catch` — good. |
| **Cache src_stat LRU** | `includes/class-cache.php:131-165` | `should_skip_combine_for_inline_budget` second filesize loop duplicate | **FIXED+VERIFIED** | `private array src_stat_cache + SRC_STAT_CACHE_LIMIT 500` `class-cache.php:131-143` + `get_cached_src_stat` FIFO `array_shift` `class-cache.php:1096-1109`. Cap matches `Image_Optimisation::FILE_EXISTS_CACHE_LIMIT` — consistent. | FIFO not true LRU (hit doesn't promote) at 500 vs ≤30 handles — negligibly less optimal than LRU but simpler. Acceptable. |
| **Bfcache dead branch** | `includes/class-bfcache.php:61-72,278-293` | `filter_nocache_headers` dead `null!==token` inner | **FIXED+VERIFIED** | Collapsed `if(null===$token){dead inner if(null!==token) ...; if(null===$token) return } else { cookie }` → `if(null===$token) return;` + single cookie-ensure `class-bfcache.php:280-293`. Doc updated to remove `wp_cache_get_salted` gate claim `class-bfcache.php:61-72` | Net -20/+10 lines, deduplicates. Early-return preserves privacy (`no-store` when no token). |
| **H-03/H-07/H-04 correctness** | `class-od-bridge.php:320` `class-ai-adaptive.php:245-303` `class-bfcache.php:280` | See CRITICAL/HIGH | **FIXED+VERIFIED** | `OD_Bridge` removed `else { non-LCP → urls }` `class-od-bridge.php:320` deletes 7 lines; `AI_Adaptive asort→arsort` + `exclude_css` query `class-ai-adaptive.php:245-272`; `bfcache` see above | `AI arsort` picks most-frequently disabled = candidates to suggest excluding — correct direction per audit, but semantic "least-used" comment is interpretive. No arch regression. |

## 2. Complexity / Design Quality

- **Net lines:** +1158/-676 across 46 PHP (many docblocks/guards). Net logic ~+300, well-documented `@since NEXT`.
- **Duplication down:** `Cloudflare_Purger` (-40-line dup, HIGH), `useApiCallWithNotice` (-4×10-line scaffold, MEDIUM), `should_bypass_for_litespeed` already centralized `class-cache.php:380` retained. No new duplication introduced except re-export alias `apiWithNotice.js` (intentional).
- **Dead code:** No new dead production function. `src_stat_cache` + `get_cached_src_stat` both used; `Cloudflare_Purger::TIMEOUT` used; `on_switch_blog` referenced via hook. SCSS `flex-center/truncate` retained intentionally per A12 X-04 (design-system library, not emitted).
- **Over-engineering check:** `Util::current_blog_id()` try/catch + `clear_settings_cache($blog_id|null|mixed)` tri-modal signature adds branching but handles BC for `delete_option_wppo_settings` action arity (old/new value args) and test stubs — justified. Could be simpler but not harmful. `apiWithNotice.js` thin re-export is minor extra file but preserves import stability.
- **God class Main** `includes/class-main.php:21` 3053 lines McCabe>30 (H-11) — **DEFERRED** per task (P4 façade extraction not in this batch). No facade attempted, correct deferral.

## 3. Hook Lifecycle / Loading

- `Main::includes()` `class-main.php:436` manually `require_once` per file + `vendor/autoload.php` for vendor only — dual mechanism retained (no PSR-4 for plugin classes). `Cloudflare_Purger` must be required before `CDN_Purger`/`Edge_Purger` use. Verified `Main::includes()` order: `class-cloudflare-purger.php` requirement precedes both (fallback `class_exists` covers race on upgrade).
- `Util::ensure_settings_cache_hook()` static `$hooked` guard `class-util.php:240-249` ensures `add_action` 4× registered once/request (not per `get_settings` call) — correct.
- `RUM::store_sample` `add_action('shutdown', flush_shutdown_buffer)` registered once via `shutdown_registered` flag `class-rum.php:349-354` — correct single registration.

## 4. Remaining Duplication / Dead Code

- SCSS 73 duplicate selector groups (510→418 distinct) from `max-width` `respond-to` media splits — expected webpack `mini-css-extract` artifact, ~2-3 KB overhead, intentional skip per P5.
- No high-confidence dead prod function (all `grep -rn` traced). `MetricCard` kept compat comment, `litespeed.js modeLabel` wired.

## 5. Verdict

**PASS.** Core correctness and duplication themes correctly fixed with minimal, well-scoped abstractions. Blog-keyed memo and shared purger extraction are clean, not over-engineered. God-class decomposition correctly deferred. No unnecessary complexity introduced.
