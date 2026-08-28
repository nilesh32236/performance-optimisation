# BEFORE-AFTER-SUMMARY.md — 2026-08-28 fix delta (master@31fffc61 → fix/audit-2026-08-28@44d7bcbf)

## Summary
- **Before:** 42 PHP 31,205 + 80 JS 22,646 + 20 SCSS 3,368 = 73k lines; 1 CRITICAL (dead `wppo_run_upgrades` hook) + 12 HIGH + ~50 MEDIUM + dup ~20 + perf stampede/RUM storm/combine triple.
- **After:** 94 files +8177/-1377, 471 PHPUnit 1134a 2 skipped (was 435), 34 Jest 345, build 55.1 KiB, 0 CRITICAL, 0 HIGH unaddressed (H-11 god class deferred with reason).

## Per-batch Before/After

| Batch | Before | After | Expected improvement | Trade-off |
|-------|--------|-------|----------------------|-----------|
| P0 C-01 `class-main.php:489` | `PerformanceOptimisation\Inc\Activate` dead hook (legacy Redis eviction never retries) | `PerformanceOptimise\Inc\Activate` resolves; `MainUpgradeHookTest` asserts registration | Correct retry on upgrade | none |
| P1 H-01 `image-optimisation:2800` | `5===count($matches)` always true under `PREG_UNMATCHED_AS_NULL`, all `<img>` mis-routed to iframe | `isset(matches[4])&&''!==` content-based, works both flag modes | WP<6.4 lazy not broken | none |
| H-02 `ai-adaptive:279` | `>3500 moderate` + `>2500 moderate` → `eager` never | `>3500 eager` `>2500 moderate` else `conservative` | AI can suggest eager | slightly more aggressive prefetch when LCP >3.5s (intended) |
| H-07 `ai-adaptive:246` | `asort` picks rarely-disabled scripts + `exclude_css` empty | `arsort` most-frequent + `_wppo_disabled_styles` query | Correct handles suggested | extra 500 postmeta scan on learn (bounded) |
| H-03 `od-bridge:318` | `else { non-LCP → LCP }` pollutes raw list | remove else, only `isLCP` adds | Correct `fetchpriority=high` | may reduce LCP candidates if OD marks non-LCP incorrectly (intended) |
| H-04 `bfcache:270` | dead inner `null!==token` cookie repair unreachable | `if(null===$token) return;` + single ensure | Cookie repair runs | none |
| H-05 `asset-manager:92` | `is_admin||is_user_logged_in` bails → logged-in not dequeued | `is_admin` only | Logged-in LCP saves | risk dequeuing logged-in required handle (protected list mitigates) |
| H-06 `metabox:54` | `''` screen never displays | `$post_types` public minus attachment | Preload UI visible | none |
| H-08 `bunny-edge.js:28` | `caches.default` + Vary fragmentation | `new Request(url,'GET')` + private guards | Bunny caches correct | extra string lowercasing per request (negligible) |
| H-12 `cloudflare-worker:52` | Vary fragmentation + private leak | `GET` request + logged-in/auth bypass + private/Set-Cookie lowercased | No poisoning, no variance | extra header inspection (negligible) |
| H-10 `App.js:285` | single AbortController aborts siblings | 3 per-request controllers `useRef` | No lost updates | 3 controllers vs 1 (negligible) |
| P2-01 `Util memo` | `get_option('wppo_settings')` 32→4 direct reads (3-6ms churn) | `Util::get_settings()` blog-keyed memo + `switch_blog` hook | 3-6ms saved per render | stale if external `update_option` without hook (hook covers) |
| P2-02 `cache:1096` | second `filesize` loop over same handles | LRU 500 `get_cached_src_stat` reuses stat | ~15 stats saved per block-theme miss | 500-entry memory (~4KB) |
| P2-03 `cron:288` | `SQL_CALC_FOUND_ROWS` + hydrate | `no_found_rows` + `update_post_meta/term_cache false` | 5-10ms/batch | none |
| P2-04 `rum:317` | 2 transient per beacon | shutdown_buffer single get/set per request (~95% reduction) | ~95% fewer get/set, 20× fewer update_option | advisory race bounded 1 sample |
| P2-05 `cron locks` | 5m lock races 50×GD 150s | 15m + 10m rescan `try/finally` | No double queue | longer lock on crash (10-15m) |
| P3 `util:125` | memo leak `switch_to_blog(2)` serves blog1 settings | `settings_cache[bid]` + `switch_blog` no-op hook | Multisite correct | per-blog array vs single |
| P3 `uninstall:92` | 5/15 transients + 0 options swept | `wppo_%` LIKE + 10 transients + per-site `switch_to_blog` loop | Clean uninstall on DB-backed caches | extra 2 queries per site |
| P4 `CloudflarePurger` | 40-line duplication CDN vs Edge | shared `Cloudflare_Purger::purge(zone,token,logTag)` | DRY, single fix-point | extra class file |
| P4 `useApiCallWithNotice` | 37-line local `withNotification` ×5 | `useApiCallWithNotice` hook + `withApiNotice` helper | DRY, consistent dismiss→notify→catch→finally | hook adds indirection (tiny) |
| P5 `sidebar.scss:18` | `left` reflow | `transform translateX` compositor | No layout thrash | none |
| P5 `fields.scss:33` | `@media 400px` bypass | `@include respond-to('xs')` map | Design-system single source | none |
| P5 `lazy/video placeholder` | no `prefers-reduced-motion` | guard `transition:none` `scale(1.1→1)` | a11y, no motion | none |

## Test delta
- 435→471 `+36` new tests `1134` assertions, 2 skipped Redis; 345 Jest unchanged (logic via PHP tests).
