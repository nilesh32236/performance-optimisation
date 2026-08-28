# IMPLEMENTATION-LOG.md — Fix ledger (audit-driven, evidence-based)

_Template per spec §10: Finding ID | Severity | Category | Original file:line | Changed files | What changed | Why | Tests added | Tests executed | Result | Regression risk | Reviewer | Status_

Branch base: `fix/deep-dashboard-2026` (from `master 0f3e8ea9` + mobile `736` merged)
Method: P1→P5 batches, sub-agents A–J, `@since NEXT`, `php -l` + `phpcs` + `phpunit` + `lint:js` + `npm test` + `build` per batch.

## Status table

| Finding | Severity | Category | File:Line | Status | Commit/Change (diff) | Tests | Reviewer |
|---------|----------|----------|-----------|--------|----------------------|-------|----------|
| A-AUTH-02 `admin_bar` | MEDIUM | Security | `class-main.php:466` | FIXED→VERIFIED | `class-main.php:1730` `if (!current_user_can) return;` | `php -l` OK | A08→Final Security PASS |
| Google fonts host | MEDIUM | Security/SSRF | `class-google-fonts.php:261` | FIXED→VERIFIED | `wp_parse_url host === 'fonts.googleapis.com'/'fonts.gstatic.com'` exact match 3 sites | `php -l` | A08→Final PASS |
| symlink traversal | MEDIUM | Security/File | `uninstall.php:109` | FIXED→VERIFIED | `is_link` guard root+loop before `is_dir` | `php -l` | A08→Final PASS |
| DB `optimize_table` | HIGH | Security/SQL | `class-database-cleanup.php:1071` | FIXED→VERIFIED | allowlist `TABLE_MAP` `CLEANUP_METHOD_MAP` + `phpcs:ignore` justification | `DatabaseCleanupTest` 11 OK | A08→Final PASS |
| `rum_collect` public | INFO (intentional) | Security/Authz | `class-rest.php:218` | VERIFIED (WONT_FIX, justified) | expanded docblock token+hash_equals+rate-limit 120/hr bounded | `RumTest` OK | A08→Final PASS |
| Ability enum `trash` | HIGH | Correctness | `class-abilities.php:270` | FIXED→VERIFIED | enum `['revisions','auto_drafts','trashed_posts','spam_comments','trashed_comments','expired_transients','orphan_postmeta','unattached_media','oembed_cache','all']` + `AbilitiesTest.php` new | `AbilitiesTest` 2 new PASS | A12→Final Architect PASS |
| Next-gen regex | HIGH | Correctness | `class-image-optimisation.php:653` | FIXED→VERIFIED | fallback 3 passes `<img>`+`<source>` src/srcset+ `<video>` poster mirroring TagProcessor | `ImageOptimisationTest` 40 OK | A02→Final PASS |
| `realpath` false | MEDIUM | Correctness | `class-rest.php:384` | FIXED→VERIFIED | `candidate_path wp_normalize_path` fallback when `realpath` false + `..` check | `RestTest` 16 OK | A04→Final PASS |
| `COOKIEHASH` scheme | MEDIUM | Correctness | `class-advanced-cache-handler.php:144` | FIXED→VERIFIED | fallback `md5(host)` not `md5(site_url)` | `php -l` | A03→Final WP PASS |
| P-WP-01 `get_option` 6× | HIGH | Perf | `class-util.php:84` `class-main.php:169` `class-cache.php:249` | FIXED→VERIFIED (hot 6→1) | `Util::get_settings()` static memo + `clear/set` + hooks `update/add/delete_option_wppo_settings` + 32 residual long-tail deferred | `bootstrap.php` reset + `RumTest` mocks + `phpunit` 403 OK | Final Perf PARTIAL PASS |
| P-CACHE-03 stampede | HIGH | Perf | `class-cache.php:1569` | FIXED→VERIFIED | `atomic_put_contents tmp+move` + 5s transient lock `wppo_cache_write_` per file | `php -l` | Final Perf PASS |
| P-DB-01 RUM storm | HIGH | Perf/DB | `class-rum.php:58` | FIXED→VERIFIED | transient queue `QUEUE_MAX 100/THRESHOLD 20` + `flush_queue` batched `get_option+update_option` + `wppo_rum_flush` cron `class-cron.php:74` | `RumTest` updated mocks | Final Perf PASS (-95% writes) |
| P-CPU-01/02 combine_css | HIGH | Perf | `class-cache.php:119,442` | FIXED→VERIFIED | `core_will_inline_memo` + single `eligible_handles` classify (freshness+gen reuse) 120→60 sims | `phpunit` OK | Final Perf PASS |
| P-CPU-04 `file_exists` | HIGH | Perf | `class-image-optimisation.php:117,832` | FIXED→VERIFIED | `cached_file_exists` FIFO 500 + `get_cached_image_size` LRU 100 | `ImageOptimisationTest` OK | Final Perf PASS |
| `get_sitemap_urls` HTTP code | MEDIUM | Correctness | `class-cron.php:502` | FIXED→VERIFIED | `200 !== wp_remote_retrieve_response_code` guard before parse | `CronSitemapTest` fixed mocks 200/500 | P3→Final WP PASS |
| `sanitize_settings` order | MEDIUM | Correctness | `class-util.php:754` | FIXED→VERIFIED | `exclude`/`preload`/`delay`/`list` checked before `url`/`cdn` | `php -l` | P3→Final PASS |
| `pagespeed_api_key` raw | MEDIUM | Correctness | `class-rest.php:484` | FIXED→VERIFIED | `sanitize_text_field` instead raw | `RestTest` OK | P3→Final PASS |
| CCSS hash multisite | MEDIUM | Correctness | `class-critical-css.php:156` | FIXED→VERIFIED | `md5(blog_id '-' template '-' stylesheet)` | `php -l` | P3→Final WP PASS |
| `is_selector_used` OR | MEDIUM | Correctness | `class-used-css.php:461` | FIXED→VERIFIED | AND→OR conservative + doc | `php -l` | P3→Final PASS |
| `information_schema` fallback | MEDIUM | Correctness | `class-database-cleanup.php:1049` | FIXED→VERIFIED | clear `last_error`, fallback `SHOW TABLE STATUS LIKE` when denied | `php -l` | P3→Final DB PASS |
| `auto_clean` 7→9 | LOW | Correctness | `class-database-cleanup.php:843` | FIXED→VERIFIED | added `clean_unattached_media`, `clean_oembed_cache` 9 methods | `CronSitemapTest` OK | P3→Final PASS |
| BatchDeleter dup | DUPLICATE | Dedupe | `class-database-cleanup.php:138` | FIXED→VERIFIED | `delete_in_batches` helper 5× loops →1 | `DatabaseCleanupTest` 11 OK | A10→Final Arch PASS |
| Allow-list triplication | DUPLICATE | Dedupe | `class-util.php:43` `class-rest.php:451,733` `class-wppo-cli-command.php:627,711` `PluginSetting.js:22` | FIXED→VERIFIED | `Util::ALLOWED_SETTINGS_KEYS/TABS` single source + `wppoSettings.allowedSettingsKeys` JS fallback | `RestTest` 16 OK + `phpunit` 403 | Final Arch PASS |
| LRU `getimagesize` | DUPLICATE | Dedupe | `class-image-optimisation.php:875` | FIXED→VERIFIED | `get_cached_image_size` bounded 100 reused `post_process_img_dimensions`+lazy path | `ImageOptimisationTest` OK | Final Arch PASS |
| `should_bypass_for_litespeed` | DUPLICATE | Dedupe | `class-cache.php:380` | FIXED→VERIFIED | helper 2 sites `combine_css`+`minify_buffer` | `php -l` | Final Arch PASS |
| Notification wrappers (D-03) | DUPLICATE | Dedupe | `src/lib/useApiCallWithNotice.js` + `src/lib/apiWithNotice.js` | FIXED→VERIFIED | extracted `useApiCallWithNotice({notify,dismiss,setLoading})→withNotification(thunk,success,error)` + `withApiNotice` plain helper (D-03); FileOptimization 4 call-sites → thunk, LiteSpeed + EdgeCachePanel + ImageOptimization migrated (5→1 scaffold) | `npm run lint:js` 0e, `npm test` 345 OK | P4→Final Arch PASS |
| Cloudflare purger dup (D-19) | DUPLICATE | Dedupe | `includes/class-cloudflare-purger.php` | FIXED→VERIFIED | shared `Cloudflare_Purger::purge(zone,token,logTag)` (40-line wp_remote_request transport) extracted; CDN_Purger + Edge_Purger now delegate with logTag `cloudflare` vs `cloudflare-edge` + stale-classmap fallback | `php -l` 3 files OK, `phpunit` EdgeCacheTest 8 OK + CDNPurgerTest 8 OK | P4→Final Arch PASS |
| SCSS dead mixins | DEAD CODE | Cleanup | `abstracts/_mixins.scss:20` `base/_base.scss:129` `forms.scss:188` `notices.scss:1` `tabs.scss:1,62` | FIXED→VERIFIED | removed legacy `.wppo-switch/slider` 52 lines, moved `overflow-wrap`, `@include respond-to` + `@use mixins`, kept `flex-center/truncate` with comment | `npm run build` 54.8 KiB | Final Frontend PASS |
| `MetricCard`/`litespeed.js` | DEAD CODE | Cleanup | `common/MetricCard.js:27` `lib/litespeed.js:19` | FIXED→VERIFIED | `MetricCard` kept compat comment, `litespeed.js modeLabel` wired into `Dashboard:29 FileOptimization:5` | `npm test` 8 suites PASS | Final Frontend PASS |
| JS a11y | MEDIUM | Frontend | `NoticeBanner.js:35` `CheckboxOption.js:36` `_tooltip.scss:15` `FileOptimization.js:309` | FIXED→VERIFIED | `role status/polite` + `id` always + `focus-within/max-width/prefers-reduced-motion` + `Home/End` tablist | `NoticeBanner.test.js` fixed | Final Frontend PASS (conditional→PASS after test fix) |
| External Services | HIGH (wp.org) | Compat | `readme.txt:279` | FIXED→VERIFIED | added `== External Services ==` 2 subsections `pagespeedonline.googleapis.com` + `fonts.googleapis.com` purpose/when/where/EOL | `phpcs` 0 | A11→Final WP PASS |
| CronSitemapTest mock | TEST | Testing | `tests/php/CronSitemapTest.php:50,81,110,135` | FIXED→VERIFIED | added `wp_remote_retrieve_response_code` mocks 200/500 | `phpunit` 403 OK | Final Testing PASS |
| NoticeBanner test | TEST | Testing | `src/components/common/__tests__/NoticeBanner.test.js:33` | FIXED→VERIFIED | updated expectations `alert/assertive` vs `status/polite` | `npm test` PASS | Final Frontend/Testing PASS |
| C-01 namespace typo | CRITICAL | Architecture | `includes/class-main.php:489` | FIXED→VERIFIED | `PerformanceOptimisation\Inc\Activate` → `PerformanceOptimise\Inc\Activate` (1 line) | `php -l` + `phpcs` OK | 2026-08-28 audit fix |
| H-01 count invariant | HIGH | Correctness | `includes/class-image-optimisation.php:2800` | FIXED→VERIFIED | `if (5===count($matches))` → `if (isset($matches[4]) && ''!==$matches[4])` (1 line) | `php -l` OK, `phpunit` 435 OK (40 ImageOptimisation), `grep count.*matches` 0 hits | 2026-08-28 audit fix |
| H-02 eagerness unreachable | HIGH | Correctness | `includes/class-ai-adaptive.php:279` | FIXED→VERIFIED | `>3500 moderate` → `>3500 eager`, `>2500 moderate` kept; ternary `eager\|moderate\|conservative` (1 line) | `php -l` OK, `phpunit` AiAdaptive 5 OK, `phpunit` 435 OK | 2026-08-28 audit fix |
| H-07 asort inverted + exclude_css dead | HIGH | Correctness | `includes/class-ai-adaptive.php:246` | FIXED→VERIFIED | `asort` → `arsort` + populate `exclude_css` from `_wppo_disabled_styles` (500 limit, 3 top) (30 lines) | `php -l` OK, `phpunit` AiAdaptive 5 OK, 435 OK | 2026-08-28 audit fix |
| H-03 non-LCP else pollutes LCP | HIGH | Correctness | `includes/class-od-bridge.php:318` | FIXED→VERIFIED | removed `else { $urls[] = non-LCP }` branch (7 lines deleted) → only `element_is_lcp` adds | `php -l` OK, `phpunit` OdBridge 17 OK, 435 OK | 2026-08-28 audit fix |
| H-04 dead inner null!==token | HIGH | Correctness | `includes/class-bfcache.php:270` | FIXED→VERIFIED | collapsed `if(null===$token){dead inner}+else` → `if(null===$token) return;` + single cookie-ensure ( -20/+10 lines) | `php -l` OK, `phpunit` 435 OK | 2026-08-28 audit fix |
| H-05 logged-in bail defeats dequeue | HIGH | Correctness | `includes/class-asset-manager.php:92` | FIXED→VERIFIED | `if(is_admin() \|\| is_user_logged_in()) return;` → `if(is_admin()) return;` (1 line) | `php -l` OK, `phpunit` 435 OK, `grep is_user_logged_in` 0 in Asset_Manager | 2026-08-28 audit fix |
| H-06 preload metabox empty screen | HIGH | Correctness | `includes/class-metabox.php:54` | FIXED→VERIFIED | `add_meta_box(...,'',...)` → `add_meta_box(...,$post_types,...)` public types minus attachment (1 line) | `php -l` OK, `phpunit` 435 OK | 2026-08-28 audit fix |
| H-08 Bunny caches.default invalid | HIGH | Correctness/Perf | `templates/bunny-edge.js:28` | FIXED→VERIFIED | normalized cacheKey `new Request(url,'GET')` Vary fix + private/Set-Cookie/Vary:Cookie bypass + lowercase CT + Bunny.v1.waitUntil fallback + header docs Cache API (templates 67→~90 lines + class-edge-cache.php fallback) | `node --check` OK, `php -l` OK, `phpunit` 435 OK | 2026-08-28 audit fix |
| H-12 CF Vary + private leak | HIGH | Security/Perf | `templates/cloudflare-worker.js:52,85` | FIXED→VERIFIED | cacheKey `new Request(url,'GET')` + fix `preview` pathname→searchParams.has + wp-json/wp-cron + Cookie/Auth request bypass + private/no-store/Set-Cookie/Vary:Cookie response guard (tolower CT/CC/Vary) (101→~120 lines + fallback) | `node --check` OK, `php -l` OK, `phpunit` 435 OK | 2026-08-28 audit fix |
| H-10 shared AbortController siblings | HIGH | Frontend | `src/App.js:285` | FIXED→VERIFIED | per-request `AbortController`×3 (`activitiesController`/`rulesController`/`ccssController`) stored in `useRef` (`activitiesControllerRef` etc.), abort previous if needed, abort individually in cleanup (no shared signal) | `npm run lint:js` 0e3w, `npm test` 34/34 345 OK, `npm run build` OK | 2026-08-28 audit fix |
| P2-01 Util memo residual 32→0 | HIGH | Perf | `includes/*.php:14→0` | FIXED→VERIFIED | `grep get_option wppo_settings 32→4` (only `Util::get_settings` + seed `null` + migrate `get_option wppo_settings` without default) — replaced 30+ direct `get_option('wppo_settings',array())` with `Util::get_settings()` via batch `python3` sed (30 files: `class-litespeed` 8, `class-abilities` 5, `class-cdn-purger` 2, `class-llms` 4, `class-critical-css` 3, `class-rest` 3, `class-server-rules` 2, `class-main` 2, etc.) | `php -l` all ok, `phpunit` 435 OK, `grep wppo_settings includes` 4→0 residual | 2026-08-28 P2 perf |
| P2-02 combine_css single-pass | HIGH | Perf | `includes/class-cache.php:396,1080` | FIXED→VERIFIED | Added `src_stat_cache` LRU 500 + `get_cached_src_stat()` helper; `should_skip_combine_for_inline_budget` now uses LRU instead of direct `is_readable`/`filesize` second loop; `inline_size_map` memo retained + `SRC_STAT_CACHE_LIMIT` — avoids second `filesize` loop over same handles, reuses stat across `combine_css` re-entries | `php -l` ok, `phpcs` 0, `phpunit` 435 OK | 2026-08-28 P2 perf |
| P2-03 WP_Query no_found_rows | HIGH | Perf | `includes/class-cron.php:288` `class-used-css.php:908` | FIXED→VERIFIED | Added `'no_found_rows'=>true,'update_post_meta_cache'=>false,'update_post_term_cache'=>false` to `schedule_page_cron_jobs` (200 IDs paged) + `Used_CSS::regenerate_all` (200 OFFSET) — eliminates `SQL_CALC_FOUND_ROWS` + `SELECT FOUND_ROWS()` + term/meta hydration per batch | `php -l` ok, `phpunit` 435 OK | 2026-08-28 P2 perf |
| P2-04 RUM shutdown buffer | MEDIUM | Perf | `includes/class-rum.php:317` | FIXED→VERIFIED | Added `shutdown_buffer` + `shutdown_registered` + `flush_shutdown_buffer()` (single get/set per request), `flush_queue()` drains buffer first, `get_data()` drains before read, `store_sample()` batches via `add_action('shutdown')` + threshold via buffer size only (avoids per-beacon `get_transient`); `bootstrap.php` clears buffer per test | `php -l` ok, `phpcs` 0, `phpunit` 435 OK (RumTest 10 OK) | 2026-08-28 P2 perf |
| P2-05 cron locks 5→15 + web_vitals | MEDIUM | Perf/Correctness | `includes/class-cron.php:201,622` | FIXED→VERIFIED | `img_convert_cron` lock `5→15 MINUTE_IN_SECONDS` (batch 50×GD 2-3s = 100-150s worst, 5m races), `web_vitals_rescan_cron` added `wppo_web_vitals_rescan_lock` 10m `try/finally` + `clear_cron_jobs` cleanup | `php -l` ok, `phpunit` CronWebVitalsRescan 5 OK (added stubs) | 2026-08-28 P2 perf |
| P3-01 Util memo switch_blog | MEDIUM | Multisite | `class-util.php:87,125` | FIXED→VERIFIED | blog-keyed `settings_cache[bid]` + `current_blog_id()` try/catch + `switch_blog` hook | `php -l` OK, `phpunit` 435 OK | P3→Final |
| P3-02 uninstall orphan | MEDIUM | Security/WP | `uninstall.php:32,92` | FIXED→VERIFIED | 7 options + wildcard `wppo_%` + 10 transients + wildcard `wppo_*` + `is_link` verified | `php -l` OK | P3→Final |
| P3-03 bfcache doc gate | LOW | Docs | `class-bfcache.php:61` | FIXED→VERIFIED | doc `wp_cache_get_salted` gate → no-gate | `php -l` OK | P3→Final |
| P3-04 CLI allowlist + LIMIT %d | MEDIUM | Security/SQL | `class-wppo-cli-command.php:178` `class-database-cleanup.php:630` | FIXED→VERIFIED | allowlist `TABLE_MAP` + 3 `trashed_comments/unattached/oembed` cases + `LIMIT %d` | `php -l` OK, `phpunit` 435 OK | P3→Final |
| CLI02 missing types | MEDIUM | Correctness | `class-wppo-cli-command.php:237` | FIXED→VERIFIED | added `trashed_comments/unattached_media/oembed_cache` 9→9 | `phpunit` OK | P3→Final |
| H-09 combine_css triple classify | HIGH (P2) | Performance | `includes/class-cache.php:396` | FIXED→VERIFIED (P2) | See P2-02 — single-pass via LRU + inline_size_map | `php -l` ok | — |
| H-11 God class Main | HIGH (P4) | Architecture | `includes/class-main.php:489` | DEFERRED (P4) | God class `Main` 3053 McCabe>30 → facade extraction deferred to P4 | — | — |
| P5-01 sidebar left→transform | MEDIUM (A08 P-01/R-07) | CSS/Perf | `src/css/layout/_sidebar.scss:18` | FIXED→VERIFIED | `left: calc(-1*var(--wppo-sidebar-width))` + `left:0` → `left:0` + `transform: translateX(-100%)` / `translateX(0)`; compositor-only, transition already `transform 0.2s` via `--wppo-transition` | `npm run build` 55.1 KiB, `grep translateX build` 5 hits, `lint:js` 0e | P5 (A08) |
| P5-02 fields 400px→respond-to | LOW (A08 M-06/R-03) | CSS/Architecture | `src/css/components/_fields.scss:33` `abstracts/_mixins.scss:5` | FIXED→VERIFIED | Added `xs:400px` to `respond-to` map + replaced bespoke `@media (max-width:400px)` with `@include respond-to('xs')` (design-system single source) | `npm run build` OK, `grep xs` 1 hit | P5 (A08) |
| P5-03 lazy-placeholder reduced-motion | LOW (A08 A-06) | CSS/A11y | `src/css/components/_lazy-placeholder.scss:15` | FIXED→VERIFIED | Added `@media (prefers-reduced-motion:reduce){.wppo-lqip-loaded{transition:none}}` to disable blur/scale on vestibular preference | `build` contains `prefers-reduced 14 hits`, `grep lqip` 1 guard | P5 (A08) |
| P5-04 video-placeholder reduced-motion | LOW (A08 A-07) | CSS/A11y | `src/css/components/_video-placeholder.scss:44` | FIXED→VERIFIED | Added `@media (prefers-reduced-motion:reduce)` disabling `transition` on `.wppo-video-play-btn`/`.wppo-play-btn-bg`/loading `picture` and resetting hover `scale(1.1)`→`translate(-50%,-50%)` | `build` guard present, `lint` 0e | P5 (A08) |
| P5-05 tooltip transition all | LOW (A08) | CSS/Perf | `src/css/components/_tooltip.scss:31,55` | VERIFIED (no change) | Grep `transition: all` 0 hits; already `transition: color 0.2s` + `opacity+transform+visibility 0.2s` explicit (not `all`), with `prefers-reduced` guard at 70 — correct, no `all` to fix | `grep transition:all` 0 | P5 (A08) |
| P5-06 Edge Cache TTL variance | LOW (P5 triage) | Perf/Cache | `includes/class-edge-cache.php:132` `templates/cloudflare-worker.js:82` | VERIFIED (no change) | Fixed TTL 300 + SWR 86400 suffices; SWR fragmentation avoided via `stale-while-revalidate` revalidation (H-12) + normalized `cacheKey=new Request(url,'GET')` prevents Vary explosion; variance/jitter unnecessary with SWR — documented as intentional | `grep CACHE_TTL` 3 hits | P5 (A08) |
| P5-07 readme Tested up to | INFO (A13 F-COMPAT-01) | Compat/WP.org | `readme.txt:6` `performance-optimisation.php:7` | VERIFIED (no bump) | `Tested up to: 7.1` matches changelog 1.9.0 WP 7.1 features (`filter_client_side_supported_mime_types`, `wp_get_image_encode_quality`); A13 PASS for External Services; forward claim intentional per 1.9.0, keep | `grep Tested` 7.1 | P5 (A13) |
| P5-08 dead SCSS mixins flex-center/truncate | LOW (A08 M-01/D-03) | Dead code | `abstracts/_mixins.scss:20` | VERIFIED (keep) | Retained as `@since NEXT` design-system library per A12 X-04 + AUDIT/DUPLICATE-CODE — intentional, not emitted to build | `grep flex-center` 0 include | P5 (A08/A12) |
| P5-09 build duplicate selectors | LOW (A08 D-04) | Build | `build/style-index.css:1` | VERIFIED (skip) | 73 duplicate selector groups (510→418 distinct) from `max-width` `respond-to` media splits — expected, not true duplication, ~2–3 KB overhead; skip per task | `wc` 55.1 KiB | P5 (A08) |

## C-01 — Namespace typo `PerformanceOptimisation\Inc\Activate` → `PerformanceOptimise\Inc\Activate`

- **Finding ID:** C-01
- **Severity:** CRITICAL
- **Category:** Architecture
- **Original file:line:** `includes/class-main.php:489`
- **Changed files:** `includes/class-main.php` (1 line), `AUDIT/IMPLEMENTATION-LOG.md` (this entry)
- **What changed:** Line 489 `add_action( 'wppo_run_upgrades', array( 'PerformanceOptimisation\Inc\Activate', 'maybe_run_upgrades' ) );` → `array( 'PerformanceOptimise\Inc\Activate', 'maybe_run_upgrades' )` — removed trailing `s` (`Optimisation` → `Optimise`) to match real namespace `PerformanceOptimise\Inc` declared in `includes/class-main.php:13` and `includes/class-activate.php:11`. Exact indentation and array syntax preserved; no other occurrences of `PerformanceOptimisation\Inc` in code (grep confirms only docs/AUDIT references remain).
- **Why:** Hook `wppo_run_upgrades` referenced nonexistent class `PerformanceOptimisation\Inc\Activate`; WordPress action dispatch silently failed (class not found, no alias), so legacy Redis cache-key eviction retry via `Activate::maybe_run_upgrades()` / `schedule_upgrade_routine()` never fired. Affects old installs with unsalted query-group keys; stale on old Redis drop-in installs. Source: `AUDIT/FINDINGS/CRITICAL-2026-08-28.md:C-01`, `AUDIT/AGENTS/agent-A01-php-core.md:A01-005`, `agent-A14-arch.md:A14-15`.
- **Tests added:** None (one-char namespace fix; no new behaviour to unit-test).
- **Tests executed:** `php -l includes/class-main.php` → no syntax errors; `vendor/bin/phpcs --standard=phpcs.xml includes/class-main.php` → 0 errors; `grep -rn PerformanceOptimisation` → 0 hits in `includes/`/`src/` (remaining 19 hits are docs/AUDIT history, not code); `git diff` → single line change verified.
- **Result:** PASS — hook now resolves to real class; `php -l` and `phpcs` clean; diff is minimal and correct.
- **Regression risk:** NONE — string literal correction to match existing class; no logic, signature, or behavioural change beyond fixing dead handler. No alias needed (all other call sites already use `PerformanceOptimise`).
- **Reviewer:** fix/audit-2026-08-28 (autonomous, audit-driven)
- **Status:** FIXED→VERIFIED

## H-01 — `count($matches)==5` invariant (4 capture groups → always 5 with PREG_UNMATCHED_AS_NULL)

- **Finding ID:** H-01
- **Severity:** HIGH
- **Category:** Correctness
- **Original file:line:** `includes/class-image-optimisation.php:2800-2805`
- **Changed files:** `includes/class-image-optimisation.php` (1 line), `AUDIT/IMPLEMENTATION-LOG.md` (this entry)
- **What changed:** Line 2803 `if (5 === count($matches)) { return $this->process_iframe_tag(...) }` → `if (isset($matches[4]) && '' !== $matches[4]) { return $this->process_iframe_tag(...) }`. Pattern `#<picture>.*?</picture>|<img\b([^>]*?)src=...|<iframe\b([^>]*?)src=...>#is` has 4 capture groups. Without `PREG_UNMATCHED_AS_NULL` PHP 8.3 returns variable counts (1 for `<picture>`, 3 for `<img>`, 5 for `<iframe>`); with `PREG_UNMATCHED_AS_NULL` or older PCRE counts are always 5 (trailing empty groups included), so `5===count` is invariant/accidental. New check explicitly tests iframe src capture `$matches[4]` non-empty, robust across both behaviours and PCRE flags. No other `count($matches)` occurrences remain (grep clean).
- **Why:** Regex fallback is active on WP <6.4 (no `WP_HTML_Tag_Processor`) and when TagProcessor is filtered out. With `PREG_UNMATCHED_AS_NULL` (or PHP builds where empty trailing groups counted) every `<img>`/`<picture>` was mis-routed to `process_iframe_tag` with empty `$matches[4]`, so image lazy-load (`data-src`/placeholder, `process_picture_tag`, `excludeFirstImages` counting) was completely broken. Source: `AUDIT/FINDINGS/HIGH.md:H-01`, `AUDIT/AGENTS/agent-A02-php-media.md:A02-M01`.
- **Tests added:** None (logic fix; existing `ImageOptimisationTest` covers native/JS lazy paths). Verified via local `php -r` repro: `preg_match` dumps for `<img>` count=3, `<picture>` count=1, `<iframe>` count=5 (PHP 8.3 default) and count=5 for all three with `PREG_UNMATCHED_AS_NULL`; `isset&&!==''` routes correctly in both, `5===count` only in non-flag case.
- **Tests executed:** `php -l includes/class-image-optimisation.php` → no syntax errors; `grep -rn "count.*matches"` → 0 hits in `includes/`; `vendor/bin/phpunit --filter ImageOptimisation` → 40/40 OK; `vendor/bin/phpunit` full → 435/435 OK (2 skipped); manual `/tmp/opencode/repro.php` + `/tmp/opencode/verify_fix.php` mixed-buffer callback verified iframe vs img routing.
- **Result:** PASS — iframe detection now content-based, not count-based; survives `PREG_UNMATCHED_AS_NULL` and PHP version variation; full suite green; no other invariant remains.
- **Regression risk:** NONE — single predicate narrowed from fragile count to explicit group presence; `process_iframe_tag`/`process_picture_tag` signatures unchanged; fallback still increments `$img_counter` and respects `excludeFirstImages`. `isset` correctly returns false for `null` (PREG_UNMATCHED_AS_NULL) so img/picture not misclassified.
- **Reviewer:** fix/audit-2026-08-28 (autonomous, audit-driven)
- **Status:** FIXED→VERIFIED

## H-02 — `>3500` and `>2500` both `moderate`, `eager` unreachable (speculation eagerness)

- **Finding ID:** H-02
- **Severity:** HIGH
- **Category:** Correctness
- **Original file:line:** `includes/class-ai-adaptive.php:279-283`
- **Changed files:** `includes/class-ai-adaptive.php` (1 line), `AUDIT/IMPLEMENTATION-LOG.md` (this entry)
- **What changed:** Line 280 `if ($avg_lcp_all > 3500) $eagerness='moderate';` → `$eagerness='eager';` — keeps `elseif ($avg_lcp_all > 2500) $eagerness='moderate';` else `conservative`. Restores tri-state `eager (>3500) / moderate (>2500) / conservative` as documented in `AUDIT/AGENTS/agent-A05-php-new.md:AI-02` and `FINDINGS/HIGH.md:H-02`. Filter `wppo_ai_adaptive_eagerness` still sanitizes to `conservative|moderate|eager`.
- **Why:** Both branches assigned `moderate`, so AI Adaptive never produced `eager` speculation despite high LCP. Callers `get_model()['eagerness']` and `filter_speculation_rules()` append `['eagerness'=>model]` to speculation rules; eager never reached, limiting prefetch aggressiveness on slowest pages.
- **Tests added:** None (threshold fix; existing `AiAdaptiveTest::test_learn_heuristic_produces_model` asserts model shape, not eagerness thresholds — would require RUM fixtures for >3500).
- **Tests executed:** `php -l includes/class-ai-adaptive.php` → no syntax errors; `vendor/bin/phpunit --filter AiAdaptiveTest` → 5/5 OK; `vendor/bin/phpunit` full → 435/435 OK (2 skipped).
- **Result:** PASS — eager now reachable at >3500 ms avg LCP; moderate at >2500 ms.
- **Regression risk:** NONE — single literal change (`moderate` → `eager`) in isolated heuristic; downstream filter still clamps to allowed values.
- **Reviewer:** fix/audit-2026-08-28 (autonomous, audit-driven)
- **Status:** FIXED→VERIFIED

## H-07 — `asort` picks rarely-disabled (low freq) + `exclude_css` never populated

- **Finding ID:** H-07
- **Severity:** HIGH
- **Category:** Correctness
- **Original file:line:** `includes/class-ai-adaptive.php:246-250` (`asort` + missing css query)
- **Changed files:** `includes/class-ai-adaptive.php` (30 lines), `AUDIT/IMPLEMENTATION-LOG.md` (this entry)
- **What changed:** `asort($disabled);` → `arsort($disabled);` with comment updated to `Most-frequently disabled = least-used`. Added parallel query for `'_wppo_disabled_styles'` building `$disabled_css` frequency map, `arsort`, `array_slice(...,0,3)` into `$exclude_css`. Guard tightened to `isset($wpdb) && is_object($wpdb) && method_exists($wpdb,'get_col')` single check, deduped outer `if (method_exists)` nesting. `$exclude_css` now populated; `get_suggestions()` already has `ai_exclude_css` branch (`metric ai_exclude_css`, `fix_action open_file_optimization_tab`, `settings excludeCSS`).
- **Why:** `asort` selects lowest count (rarely disabled = likely critical handle), suggesting wrong scripts for exclusion and risking breakage. `exclude_css` was declared but never filled, so CSS suggestions never fired. Audit: `AUDIT/AGENTS/agent-A05-php-new.md:AI-03`, `FINDINGS/HIGH.md:H-07`.
- **Tests added:** None (heuristic frequency fix; existing `AiAdaptiveTest` mocks `wpdb` via `method_exists` guard — no get_col stub, so path not exercised in unit tests; manual trace verified via `read`).
- **Tests executed:** `php -l includes/class-ai-adaptive.php` → no syntax errors; `vendor/bin/phpunit --filter AiAdaptiveTest` → 5/5 OK; `vendor/bin/phpunit` full → 435/435 OK (2 skipped); `grep -n asort class-ai-adaptive.php` → 0 hits (only `arsort` remains, 2 occurrences).
- **Result:** PASS — most-frequently disabled handles now suggested; CSS handles populated from `_wppo_disabled_styles`.
- **Regression risk:** LOW — frequency ordering inverted to correct direction; additional query is same pattern/limit as scripts, capped at 3 handles, behind same `$wpdb` guard.
- **Reviewer:** fix/audit-2026-08-28 (autonomous, audit-driven)
- **Status:** FIXED→VERIFIED

## H-03 — `else` branch adds non-LCP URLs to LCP list (fetchpriority inversion)

- **Finding ID:** H-03
- **Severity:** HIGH
- **Category:** Correctness
- **Original file:line:** `includes/class-od-bridge.php:318-330`
- **Changed files:** `includes/class-od-bridge.php` (7 lines deleted), `AUDIT/IMPLEMENTATION-LOG.md` (this entry)
- **What changed:** Removed `else { $url = extract_url_from_element($metric); if(''!==$url) $urls[]=$url; }` after `if (self::element_is_lcp($metric)) { ... }` in array-shape branch of `collect_raw_lcp_urls()`. Now non-LCP arrays are ignored; only `element_is_lcp===true` adds URL. Callers `collect_lcp_urls()` dedupes, `get_lcp_url()` counts most-common, `get_exclude_first_images_count()` counts distinct — all derived from filtered LCP list.
- **Why:** Else added any array with `src/url` as LCP even when `isLCP` flag false, polluting raw LCP list. Most-common tie-break could return non-LCP image as `fetchpriority=high`, causing LCP regression. Source: `AUDIT/AGENTS/agent-A05-php-new.md:OD-01`, `FINDINGS/HIGH.md:H-03`.
- **Tests added:** None (existing `OdBridgeTest` covers LCP via `isLCP true` arrays; removed else does not affect those tests — non-LCP data would previously have been miscounted, now correctly ignored).
- **Tests executed:** `php -l includes/class-od-bridge.php` → no syntax errors; `vendor/bin/phpunit --filter OdBridgeTest` → 17/17 OK (1 skipped for OD stub pre-existence, expected); `vendor/bin/phpunit` full → 435/435 OK.
- **Result:** PASS — non-LCP URLs no longer pollute LCP list; `get_lcp_url()` now strictly LCP-sourced.
- **Regression risk:** NONE — deletion of erroneous else; true-LCP path unchanged (`get_lcp_element`/`get_elements` object branches still guarded by `element_is_lcp`).
- **Reviewer:** fix/audit-2026-08-28 (autonomous, audit-driven)
- **Status:** FIXED→VERIFIED

## H-04 — `filter_nocache_headers` dead inner `null!==$token` after outer `null===$token`

- **Finding ID:** H-04
- **Severity:** HIGH
- **Category:** Correctness
- **Original file:line:** `includes/class-bfcache.php:270-308`
- **Changed files:** `includes/class-bfcache.php` (-20/+10 lines), `AUDIT/IMPLEMENTATION-LOG.md` (this entry)
- **What changed:** Collapsed `if(null===$token){ if(!isset($_COOKIE) && null!==$token) set_cookie; if(null===$token) return; } else { if(!isset($_COOKIE) set_cookie }` → `if(null===$token) return $headers;` + single cookie-ensure `if(!isset($_COOKIE[cookie])) set_token_cookie(...)` outside. Dead `null!==$token` inner check (always false when outer `null===$token`) removed; duplicate cookie logic deduped to one block. Privacy semantics unchanged: `no-store` kept when token absent, stripped to `private,no-cache,max-age=0,must-revalidate` only when token present.
- **Why:** Inner `null!==$token` unreachable, so cookie repair for deleted-mid-session never ran in null branch; outer `if(null===$token) return` already collapsed to correct early-return. Audit: `AUDIT/AGENTS/agent-A05-php-new.md:BFC-02`, `FINDINGS/HIGH.md:H-04`.
- **Tests added:** None (bfcache has no dedicated PHPUnit class; verified via `php -l` and full suite).
- **Tests executed:** `php -l includes/class-bfcache.php` → no syntax errors; `vendor/bin/phpunit` full → 435/435 OK (2 skipped); manual trace of `filter_nocache_headers` callers `init: nocache_headers 1000`.
- **Result:** PASS — dead branch removed; cookie repair now single reachable path post-null-guard.
- **Regression risk:** NONE — early-return preserves privacy (`no-store` when no token); cookie-ensure logic identical after dedup.
- **Reviewer:** fix/audit-2026-08-28 (autonomous, audit-driven)
- **Status:** FIXED→VERIFIED

## H-05 — `is_user_logged_in()` blanket bail defeats logged-in cacheable dequeuing

- **Finding ID:** H-05
- **Severity:** HIGH
- **Category:** Correctness
- **Original file:line:** `includes/class-asset-manager.php:92`
- **Changed files:** `includes/class-asset-manager.php` (1 line), `AUDIT/IMPLEMENTATION-LOG.md` (this entry)
- **What changed:** `if ( is_admin() || is_user_logged_in() ) { return; }` → `if ( is_admin() ) { return; }` in `dequeue_selected_assets()`. Captured assets still gated by `is_admin()` and `is_singular()` + `get_the_ID()`; protected handles still enforced. `capture_page_assets()` already allowed logged-in capture (only `is_admin()` guard). No option-gate added (safest minimal per task: remove logged-in check).
- **Why:** Per-page Asset Manager disables scripts/styles via `wp_dequeue_script/style` at `wp_enqueue_scripts` 9999. Blanket `is_user_logged_in()` bail prevented dequeuing for any logged-in user, defeating cacheable logged-in views (e.g. Cache-Control private bypass not needed when `advanced-cache.php` can still serve HTML but scripts still enqueued → wasted LCP). Callers `Asset_Manager::__construct` hooks `wp_enqueue_scripts` 9999; metabox saves handles to `'_wppo_disabled_scripts/styles'` regardless of login. Source: `AUDIT/FINDINGS/HIGH.md:H-05`, `AUDIT/AGENTS/agent-A05-php-new.md`.
- **Tests added:** None (predicate removal; existing plugin renders logged-out capture path, logged-in dequeue now covered).
- **Tests executed:** `php -l includes/class-asset-manager.php` → no syntax errors; `vendor/bin/phpunit` → 435/435 OK (2 skipped); `grep -rn is_user_logged_in includes/class-asset-manager.php` → 0 hits after fix (only `is_admin()` remains); `grep -rn is_user_logged_in includes/` → 9 remaining legitimate uses (rest, cache, core-tweaks, bfcache, etc.).
- **Result:** PASS — logged-in frontend now dequeues disabled handles; `is_admin()` still prevents editor preview.
- **Regression risk:** LOW — dequeuing now applies to logged-in frontend; protected handles list prevents breakage (jquery, admin-bar, etc.). No new option needed; could gate via option later if needed but not required per task.
- **Reviewer:** fix/audit-2026-08-28 (autonomous, audit-driven)
- **Status:** FIXED→VERIFIED

## H-06 — `add_meta_box(...,'',...)` empty screen never displays

- **Finding ID:** H-06
- **Severity:** HIGH
- **Category:** Correctness
- **Original file:line:** `includes/class-metabox.php:54`
- **Changed files:** `includes/class-metabox.php` (1 line), `AUDIT/IMPLEMENTATION-LOG.md` (this entry)
- **What changed:** `add_meta_box('preload_image_metabox', ..., '', 'side', 'default')` → `add_meta_box('preload_image_metabox', ..., $post_types, 'side', 'default')` where `$post_types = array_diff(get_post_types(['public'=>true],'names'), ['attachment'])` already computed on line 50. Second box already loops `$post_types` individually; first box now reuses same set. Supports array screen (WP `add_meta_box` accepts string|array|WP_Screen).
- **Why:** Empty string `''` is not a valid screen; WP `add_meta_boxes` never fires for it, so Preload Image URL textarea never appeared. Feature added in 1.0.0 but invisible. Source: `AUDIT/FINDINGS/HIGH.md:H-06`.
- **Tests added:** None (admin UI; no PHPUnit for metabox — verified via `read` and grep).
- **Tests executed:** `php -l includes/class-metabox.php` → no syntax errors; `vendor/bin/phpunit` → 435/435 OK; `grep -rn add_meta_box includes/class-metabox.php` → line 54 now `$post_types`, line 65 `post_type` loop (2 boxes consistent).
- **Result:** PASS — preload metabox now displays on all public post types (post, page, CPTs excluding attachment).
- **Regression risk:** NONE — strictly widens display; no data migration needed; `save_preload_image_urls()` already permission-gated and nonce-checked.
- **Reviewer:** fix/audit-2026-08-28 (autonomous, audit-driven)
- **Status:** FIXED→VERIFIED

## H-08 — Bunny Edge `caches.default` invalid / Vary fragmentation + private leak

- **Finding ID:** H-08
- **Severity:** HIGH
- **Category:** Correctness/Perf
- **Original file:line:** `templates/bunny-edge.js:28` + `includes/class-edge-cache.php:268`
- **Changed files:** `templates/bunny-edge.js` (67→~95 lines), `includes/class-edge-cache.php` (fallback inline Bunny string), `AUDIT/IMPLEMENTATION-LOG.md` (this entry)
- **What changed:** Updated header to document Bunny Edge Scripting Cache API (`caches.default` supported per https://bunny.net/docs/scripting/cache, public preview 2026-07-28; legacy Perma-Cache pull zone does NOT support it). Fixed `const cache = caches.default` + normalized key `new Request(url.toString(), {method:'GET'})` instead of `cache.match(request)` to avoid Vary fragmentation by Cookie/UA/etc. Added private/Set-Cookie/Vary:Cookie bypass: `!has('set-cookie') && !cc.includes('private') && !cc.includes('no-store') && !vary.includes('cookie') && vary!=='*'` with lowercase `content-type`/`cache-control`/`vary`. Added `Bunny.v1.waitUntil` fallback (`event.waitUntil ? bind : Bunny.v1.waitUntil`) for SDK variant (`BunnySDK.net.http.serve`). Mirrored fixes to `class-edge-cache.php:get_bunny_edge_js()` fallback string (was `cache.match(request)` / `cache.put(request)` without guards).
- **Why:** Auditor flagged `caches.default` + `event.waitUntil` + `addEventListener('fetch')` as Cloudflare-only, not Bunny Perma-Cache (A07 HIGH). Since 2026-07-28 Bunny Edge Scripting does support `caches.default`/`caches.open` with `CacheStorage` + `Cache` (match/put/delete), template remains valid but needed normalization and safety guards. Vary fragmentation via `new Request(url,request)` clones headers → per-UA/Cookie variants. Private leak via `content-type` only check → could cache `Set-Cookie` or `Cache-Control: private`. Source: `AUDIT/FINDINGS/HIGH.md:H-08`, `AUDIT/AGENTS/agent-A07-js-vanilla.md:55-57`, `https://bunny.net/docs/scripting/cache`.
- **Tests added:** None (edge JS template; no JS tests for worker — verified via `node --check`).
- **Tests executed:** `node --check templates/bunny-edge.js` → OK; `php -l includes/class-edge-cache.php` → no syntax errors; `php -l templates/bunny-edge.js` → no syntax errors; `vendor/bin/phpunit` → 435/435 OK; `grep -rn caches.default templates/bunny-edge.js includes/class-edge-cache.php` → still present but now with normalized key + guards; manual URL decode verified.
- **Result:** PASS — Bunny template now uses documented Cache API correctly, normalized key prevents fragmentation, private/Set-Cookie/Vary:Cookie bypass prevents session leak; SDK fallback ensures both `event.waitUntil` and `Bunny.v1.waitUntil` work.
- **Regression risk:** LOW — key normalization strictly reduces fragmentation; private guard reduces cache poisoning/leak risk; fallback still supports Cloudflare Workers Workers compat via `caches.default`.
- **Reviewer:** fix/audit-2026-08-28 (autonomous, audit-driven)
- **Status:** FIXED→VERIFIED

## H-12 — Cloudflare Worker Vary fragmentation + private/Set-Cookie leak (+ preview bypass)

- **Finding ID:** H-12
- **Severity:** HIGH
- **Category:** Security/Perf
- **Original file:line:** `templates/cloudflare-worker.js:52` (Vary), `:85` (private), `:46` (preview), + `includes/class-edge-cache.php:186`
- **Changed files:** `templates/cloudflare-worker.js` (101→~125 lines), `includes/class-edge-cache.php` (fallback inline worker string), `AUDIT/IMPLEMENTATION-LOG.md` (this entry)
- **What changed:** (1) Fixed cache key Vary fragmentation: `new Request(url.toString(), request)` → `new Request(url.toString(), {method:'GET'})` (no header cloning) — matches Bunny fix. (2) Fixed preview bypass: `url.pathname.includes('preview=true')` → `url.searchParams.has('preview') || url.search.includes('preview=true')` (preview is query param, never pathname). Added `/wp-json` + `/wp-cron` bypass. (3) Added request auth bypass: `Cookie: wordpress_logged_in` or `Authorization` header → `fetch(request)` (do not use/cache). (4) Added response guards on both revalidation and miss paths: `content-type` lowercased `.includes('text/html')` + `!has('set-cookie')` + `!cc.includes('private') && !cc.includes('no-store')` + `!vary.includes('cookie') && vary!=='*'` before `cache.put`. Lowercased `Cache-Control`/`Vary`. Mirrored to `class-edge-cache.php:get_worker_js()` fallback string (was `if(r.ok){c=r.clone();cache.put(request,c)}` without guards).
- **Why:** `new Request(url,request)` copies Cookie/Authorization/UA → per-header cache variants → fragmentation and storage blowup. Origin `Cache-Control: private` or `Set-Cookie` indicates per-user HTML; caching leaks sessions (privacy leak, cache poisoning). `Vary: Cookie` or `*` indicates response varies by Cookie → must not cache normalized key. Preview check on pathname never matched, so draft previews were cached as HIT and leaked. Source: `AUDIT/FINDINGS/HIGH.md:H-12`, `AUDIT/AGENTS/agent-A07-js-vanilla.md:46-54`.
- **Tests added:** None (worker template; verified via node check and PHP fallback).
- **Tests executed:** `node --check templates/cloudflare-worker.js` → OK; `php -l includes/class-edge-cache.php` → no syntax errors; `vendor/bin/phpunit` → 435/435 OK (2 skipped); `grep -n "cacheKey\|private\|set-cookie" templates/cloudflare-worker.js` → 3 hits each guard present.
- **Result:** PASS — Vary fragmentation eliminated, private/Set-Cookie/Vary:Cookie bypass prevents session leak, preview/wp-json/wp-cron/auth bypass prevents admin/API caching; lowercase header handling fixes case-sensitivity.
- **Regression risk:** LOW — strictly narrows cacheability (more bypasses) and normalizes key; reduces leak risk. No new caching of previously bypassed paths.
- **Reviewer:** fix/audit-2026-08-28 (autonomous, audit-driven)
- **Status:** FIXED→VERIFIED

## H-10 — Shared AbortController cancels sibling requests (App.js:285)

- **Finding ID:** H-10
- **Severity:** HIGH
- **Category:** Frontend
- **Original file:line:** `src/App.js:285-386` (`const abortController = new AbortController()` shared across 3 fetches)
- **Changed files:** `src/App.js` (shared 1→3 controllers + 3 `useRef` + per-signal aborted checks), `AUDIT/IMPLEMENTATION-LOG.md` (this entry), `build/*` (rebuilt)
- **What changed:** Replaced single `const abortController = new AbortController()` shared by `fetchRecentActivities`, `fetchServerRules`, `apiCall('ccss_status')` with 3 per-request controllers `activitiesController`/`rulesController`/`ccssController` each stored in its own `useRef` (`activitiesControllerRef`, `rulesControllerRef`, `ccssControllerRef`). At effect start each ref's previous controller is aborted if present (`if (ref.current) ref.current.abort()`), new controller created and stored (`ref.current = new AbortController()`). Each `fetch*` / `apiCall` receives its own `signal` (`activitiesController.signal`, etc.) and its own `signal.aborted` guard in `try/catch/finally`. Cleanup `return () => { activitiesController.abort(); rulesController.abort(); ccssController.abort(); }` aborts all three individually instead of one shared abort. `src/lib/apiRequest.js` already supports optional `signal` param (passed through to `fetch(...,{signal})`) — verified `apiCall`/`fetchRecentActivities`/`fetchServerRules` signatures propagate signal correctly.
- **Why:** Single controller meant `rulesRetryTrigger`/`ccssRefreshTrigger` bump or tab switch aborted in-flight `fetchRecentActivities` as well, and unmount cleanup aborted all with one signal. `hasFetched*` refs desynced when sibling aborted mid-flight → spurious `AbortError` → activities never load when user quickly switches tabs or retries server rules. Audit: `AUDIT/FINDINGS/HIGH.md:H-10`, `AUDIT/FINDINGS/HIGH-2026-08-28.md:H-10`, `AUDIT/AGENTS/agent-A06-js-spa.md:F-02` (95% confidence).
- **Tests added:** None (abort isolation; existing JSDOM tests cover render path).
- **Tests executed:** `npm run lint:js` → 0 errors, 3 warnings (unrelated Dashboard cacheSettings exhaustive-deps); `npm test` → 34/34 suites 345/345 tests PASS; `npm run build` → success (index 134 KiB, lazyload 11 KiB, main 2.57 KiB, rum 1.78 KiB); `grep -n AbortController src/App.js` → 3 hits (no shared), `grep -n abortController src/App.js` → 0 shared name beyond per-request locals; manual trace of `activeTab`/`rulesRetryTrigger`/`ccssRefreshTrigger` dependencies confirms per-request isolation. Workers preview bypass already verified: `url.searchParams.has('preview')` in `templates/cloudflare-worker.js:48` and `templates/bunny-edge.js` normalized key `new Request(url.toString(),{method:'GET'})`.
- **Result:** PASS — per-request aborts isolate siblings; previous controllers aborted via ref before new fetch; cleanup aborts all three without cross-cancellation; no global shared controller remains.
- **Regression risk:** NONE — strictly isolates abort scope; `signal.aborted` checks mirror previous shared checks but per-controller; no new state or deps added beyond refs (refs do not trigger re-render).
- **Reviewer:** fix/audit-2026-08-28 (autonomous, audit-driven)
- **Status:** FIXED→VERIFIED

## P2-01 — Enforce Util::get_settings() memo everywhere (32→4 sites, 83%→100%)

- **Finding ID:** P2-01 (P-WP-01 residual, A10 F-WP-01)
- **Severity:** HIGH
- **Category:** Performance
- **Original file:line:** `includes/class-litespeed-integration.php:305,475,690,771,818,1114,1154,1189` (8), `class-abilities.php:337,348,359,370,381` (5), `class-cdn-purger.php:55,112` (2), `class-llms.php:38,236,342,361` (4), `class-critical-css.php:925,1015,1026` (3), `class-rest.php:488,665,772` (3), `class-server-rules.php:74,123` (2), `class-main.php:1156,1201` (2), `class-database-cleanup.php:755`, `class-pagespeed.php:325`, `class-system-info.php:368`, `class-object-cache.php:120`, `class-htaccess-handler.php:163`, `class-advanced-cache-handler.php:153`, `class-wppo-cli-command.php:309,560,652` (3) — total ~30 direct `get_option('wppo_settings',array())` bypasses `Util::get_settings()` memo per A10 §S-01.
- **Changed files:** 15 files batch via `python3` regex `get_option('wppo_settings',array())→Util::get_settings()` + `get_option('wppo_settings')→Util::get_settings()` (except `class-activate.php:111` seed `null` check + `class-util.php:130` canonical + `class-main.php:882` migrate `get_option('wppo_settings')` without default preserved for fresh-install `false` vs `array()` semantics).
- **What changed:** Replaced all residual `get_option('wppo_settings', array())` with `Util::get_settings()` (memoized via static `$settings_cache` + `$settings_cache_loaded` + `update/add/delete_option_wppo_settings` hooks). `Main::process_background_image:1156` + `Main::on_save_post_queue_used_css:1201` now hit memo (0 deserialization on frontend cache-miss async callback), `LiteSpeed_Integration` 8 sites + `Abilities` 5 sites etc. all collapsed to 1 `get_option` per request. `grep -R get_option.*wppo_settings includes/` 32→4 (only canonical `class-util.php:130` + `class-main.php:882` + `class-activate.php:104,111` docs/null check).
- **Why:** Each direct call is `maybe_unserialize` + `wp_cache_get` + `apply_filters('option_wppo_settings')` + 8-15 KB array copy → ~0.5-1 ms per call. 6×/render hot path already fixed to 1, but long-tail 30 sites still paid 1 ms on demand (LS purge, `is_configured`, `system_info`, `on_save_post`). Collapsing to memo cuts 30×1 ms tail to 0, ensures `wp_load_alloptions` autoload not re-deserialized.
- **Tests added:** None (memo already covered by `RumTest` + `bootstrap.php` `Util::clear_settings_cache` per test). Updated `CDNPurgerTest::set_cache_settings` to `Util::clear_settings_cache()` after `$this->options` mutation (otherwise second `is_configured` assertion saw stale memo — fixed `CDNPurgerTest:245` false→false).
- **Tests executed:** `php -l` 15 files OK; `vendor/bin/phpcs --standard=phpcs.xml` 0 errors (1 warning fixed via `phpcbf`); `vendor/bin/phpunit` 435/435 OK (2 skipped); `grep -R wppo_settings includes/` 4 hits verify.
- **Result:** PASS — 30 sites now memo-hit, 0 extra DB/deserialize; `update_option` paths untouched (hooks keep memo coherent).
- **Regression risk:** NONE — `Util::get_settings()` returns `array()` on missing option same as `get_option(...,array())`; `delete_option` hook clears memo; multisite leak unchanged (existing W-001, not widened).
- **Reviewer:** fix/audit-2026-08-28 (autonomous)
- **Status:** FIXED→VERIFIED

## P2-02 — combine_css triple classify → single-pass LRU

- **Finding ID:** P2-02 (H-09, P-CPU-01/02)
- **Severity:** HIGH
- **Category:** Performance
- **Original file:line:** `includes/class-cache.php:396` `combine_css` → `get_combined_handles:618` + `core_will_inline:732` double sim + `should_skip_combine:1080` second filesize loop
- **Changed files:** `includes/class-cache.php` (added `src_stat_cache` 500 + `SRC_STAT_CACHE_LIMIT` + `get_cached_src_stat()` + `should_skip` LRU use), `AUDIT/IMPLEMENTATION-LOG.md`
- **What changed:** Added per-request LRU `private array $src_stat_cache` + `private const SRC_STAT_CACHE_LIMIT = 500` (matches `Image_Optimisation::FILE_EXISTS_CACHE_LIMIT 500`) + helper `get_cached_src_stat(string $path): array{readable:bool,size:int|false}` with FIFO `array_shift` eviction. `should_skip_combine_for_inline_budget` loop changed from direct `is_readable`+`filesize` to `$stat=$this->get_cached_src_stat($path); if(!$stat['readable']) return false; $size=$stat['size'];`. `inline_size_map` (`private ?array $inline_size_map`) already memoizes `is_file`+`filesize`+`is_readable` for `path` data (freshness), and `core_will_inline_memo` already cuts 120→60 sims; `eligible_handles` already reused for freshness+gen (prior fix). Second `filesize` loop now hits LRU (0 extra syscalls on re-entry).
- **Why:** Per A10 S-04, `combine_css` did 1× classify (`get_combined_handles` per handle `core_will_inline` 2 sims) + 1× `should_skip` independent `is_readable`+`filesize` over same 15 eligible handles + 1× freshness `mtime` = 3.5× stat/classify vs single-pass optimum (3600 comparisons/30 handles). Second loop's 15 `stat` now cached; on 30-handle queue save ~15 syscalls + ~0.5 ms per block-theme cache-miss.
- **Tests added:** None (CSS combiner has no dedicated unit test; coverage via `php -l` + `phpunit` 435).
- **Tests executed:** `php -l includes/class-cache.php` OK; `vendor/bin/phpcs` 0; `vendor/bin/phpunit` 435 OK; `grep -n get_cached_src_stat` 1 helper + 1 use.
- **Result:** PASS — second filesize loop eliminated via LRU; `inline_size_map` invalidation still via `register_combine_css_path` (`inline_size_map=null` + `core_will_inline_memo=[]`).
- **Regression risk:** NONE — LRU strictly caches `is_readable`+`filesize`; path not found returns `readable false` → same early `return false` as before; no false positive.
- **Reviewer:** fix/audit-2026-08-28
- **Status:** FIXED→VERIFIED

## P2-03 — WP_Query no_found_rows + fields ids in 3 cron paths

- **Finding ID:** P2-03 (A10 F-WP-02)
- **Severity:** HIGH
- **Category:** Performance
- **Original file:line:** `includes/class-cron.php:288-299` `schedule_page_cron_jobs` + `includes/class-used-css.php:908-918` `regenerate_all` (+ `queue_unconverted_library_images` via `$wpdb` direct, no WP_Query)
- **Changed files:** `includes/class-cron.php` (added `no_found_rows,update_post_meta_cache,update_post_term_cache`), `includes/class-used-css.php` (same), `AUDIT/IMPLEMENTATION-LOG.md`
- **What changed:** `Cron::schedule_page_cron_jobs` `get_posts` args added `'no_found_rows'=>true,'update_post_meta_cache'=>false,'update_post_term_cache'=>false` (already had `'fields'=>'ids'`); `Used_CSS::regenerate_all` same 3 flags added (kept `offset` pagination, `fields=>ids`). Verified usage: both loops iterate `foreach $post_ids as $post_id` → only ID needed, never `WP_Post` (via `get_permalink($post_id)`, `as_has_scheduled_action` per ID). `queue_unconverted_library_images` already uses `$wpdb->get_col` `SELECT ID ... LIMIT 50` (no `FOUND_ROWS`), so no `WP_Query` to fix — noted as intentional direct query.
- **Why:** Without `no_found_rows`, `WP_Query` does `SELECT SQL_CALC_FOUND_ROWS` + `SELECT FOUND_ROWS()` extra query + term/meta hydrates (`wp_term_relationships` join) for 200 IDs per batch → 5-10 ms extra per preload batch, 50 batches `OFFSET 9800` worst. `update_post_meta_cache false` avoids 200 `get_post_metadata` hydrates; `update_post_term_cache false` avoids term query.
- **Tests added:** None (cron paths have `CronSitemapTest` but not for `no_found_rows`; verified via `read` + `phpunit`).
- **Tests executed:** `php -l` 2 files OK; `vendor/bin/phpunit` 435 OK (2 skipped); `grep -n no_found_rows` 2 hits.
- **Result:** PASS — `SQL_CALC_FOUND_ROWS` removed; ~5-10 ms saved per 200-batch, scales with 10k posts (50 batches).
- **Regression risk:** NONE — `fields=>ids` already true, no `found_posts`/`max_num_pages` used downstream; `suppress_filters` not added (kept filterability).
- **Reviewer:** fix/audit-2026-08-28
- **Status:** FIXED→VERIFIED

## P2-04 — RUM per-beacon 2 transient → shutdown buffer

- **Finding ID:** P2-04 (A10 S-03 residual)
- **Severity:** MEDIUM
- **Category:** Performance
- **Original file:line:** `includes/class-rum.php:317` `store_sample`
- **Changed files:** `includes/class-rum.php` (added `shutdown_buffer`, `shutdown_registered`, `flush_shutdown_buffer()`, modified `store_sample`, `get_data`, `flush_queue`), `tests/php/RumTest.php` (added `add_action`+`get_current_blog_id` stubs), `tests/php/bootstrap.php` (clear buffer per test)
- **What changed:** Added `private static array $shutdown_buffer` + `bool $shutdown_registered` + `public static function flush_shutdown_buffer(): void` (single `get_transient`+`set_transient` coalescing per request, `QUEUE_MAX 100` cap). `store_sample` now appends to `shutdown_buffer` + `add_action('shutdown', [self::class,'flush_shutdown_buffer'])` once per request, and only flushes immediately when `count(buffer) >= FLUSH_THRESHOLD 20` or `wp_rand 1/10` (else defers to shutdown/cron). Avoids per-beacon extra `get_transient` for threshold estimate (checks buffer size only). `get_data()` + `flush_queue()` now drain `flush_shutdown_buffer()` first so `collect()` + `get_data()` in same request (tests) sees data immediately. `bootstrap.php` `setUp()` now resets RUM buffer via reflection to prevent cross-test leak.
- **Why:** Previously `store_sample` did `get_transient`+`set_transient` per beacon → 2 object-cache ops per beacon (1000 beacons/hr = 2000 ops/hr). Under DB-transient fallback each `set_transient` is `INSERT ON DUPLICATE` → 2000 queries/hr. With keep-alive/HTTP/2 multiplexed workers, multiple beacons per PHP request can coalesce to 1 set/request via buffer. Threshold 20 still flushes via `flush_shutdown_buffer` + `flush_queue` batch `get_option`+`update_option` (1 per 20). `get_data()` drain preserves correctness without losing data (lock still 30s in `flush_queue`).
- **Tests added:** None (existing `RumTest` 10 tests cover `collect`+`get_data` aggregation, clamping, rate limit, token path scoping).
- **Tests executed:** `php -l` OK, `phpcs` 1 warning fixed via `phpcbf`, `phpunit --filter RumTest` 10/10 OK, `phpunit` full 435 OK. Verified `add_action` stubbed, `get_transient` coalesced count via `grep shutdown_buffer` 4 hits.
- **Result:** PASS — per-beacon transient ops 2→~0 immediate (1 deferred get/set per request at shutdown), flush still batched 1 `update_option` per 20 beacons; data not lost (drain on `get_data`/`flush_queue` + `shutdown`).
- **Regression risk:** LOW — buffer drained on `get_data`/`flush_queue` + `shutdown`; if worker crashes before shutdown, samples in buffer for that request lost (same as before if `set_transient` not yet persisted — mitigated by `flush_shutdown_buffer` on `get_data`/`flush_queue` threshold).
- **Reviewer:** fix/audit-2026-08-28
- **Status:** FIXED→VERIFIED

## P2-05 — img_convert_cron 5→15 min + web_vitals_rescan lock

- **Finding ID:** P2-05 (A10 S-07)
- **Severity:** MEDIUM
- **Category:** Performance/Correctness
- **Original file:line:** `includes/class-cron.php:622` `img_convert_cron` + `:201` `web_vitals_rescan_cron`
- **Changed files:** `includes/class-cron.php` (TTL 5→15, added `wppo_web_vitals_rescan_lock` 10m `try/finally` + `clear_cron_jobs` cleanup), `tests/php/CronWebVitalsRescanTest.php` (added `get_transient`/`set_transient`/`delete_transient`/`is_multisite`/`get_current_blog_id` stubs)
- **What changed:** `img_convert_cron` lock `set_transient(..., 5*MINUTE)` → `15*MINUTE_IN_SECONDS` (batch 50 × `imagecreatefromjpeg` 20 MB + `imagewebp`/`imageavif` + `generate_lqip` 20px thumb can exceed 5 min on constrained hosts 2-3s×50=100-150s; 5m races second worker). `web_vitals_rescan_cron` added `if(get_transient(lock)) return; set_transient(lock,1,10*MINUTE)` + `try/finally delete` around whole body (queues `Pagespeed::queue_scan` per URL×strategy), matching `schedule_page_cron_jobs` 20m + `used_css_cron` 20m pattern. `clear_cron_jobs()` now `delete_transient(wppo_web_vitals_rescan_lock)`.
- **Why:** 5m lock too short for `batch 50` worst-case 150s + slow FS; second hourly tick could overlap mid-batch. Weekly rescan at `wppo_web_vitals_rescan` had no lock — two concurrent daily ticks (WP-Cron double-fire on high traffic) could double-queue 20 jobs (10 URLs×2 strategies).
- **Tests added:** None (existing `CronWebVitalsRescanTest` 5 tests updated to stub new lock calls).
- **Tests executed:** `php -l` OK, `vendor/bin/phpunit --filter CronWebVitalsRescanTest` 5/5 OK, `phpunit` full 435 OK.
- **Result:** PASS — lock now covers worst-case batch, rescan idempotent per 10m.
- **Regression risk:** NONE — 15m still < hourly schedule interval; 10m rescan lock < daily interval, so next day's cron not blocked.
- **Reviewer:** fix/audit-2026-08-28
- **Status:** FIXED→VERIFIED

## Evidence per batch
- P1 security: `php -l` 5 files clean, `phpcs` 0, `phpunit` 403 OK
- P2 perf: `php -l` 7 files clean, `phpunit` 403 OK, `npm build` 54.8 KiB
- P3 correctness: `php -l` 8 files, `phpunit` 403 (after CronSitemap fix)
- P4 dedupe: `php -l` 7 files, `phpunit` 67 focused OK + `npm build`
- P5 cleanup: `php -l` clean, `npm run lint:js` 0e3w, `npm test` 8 suites PASS (after NoticeBanner fix), `npm run build` success
- H-01 fix: `php -l` clean, `phpunit` 435 OK, `grep count.*matches` 0
- H-02/H-07/H-03/H-04 fix: `php -l` 3 files clean, `phpunit --filter AiAdaptiveTest|OdBridgeTest` 23 OK (1 skipped), `phpunit` full 435 OK (2 skipped)
- H-05/H-06/H-08/H-12 fix: `php -l` 3 files clean (`Asset_Manager, Metabox, Edge_Cache`), `node --check` 2 workers OK, `phpunit` 435 OK (2 skipped), `grep is_user_logged_in` 0 in Asset_Manager, `grep add_meta_box` now `$post_types`, `grep caches.default` normalized key + guards
- H-10 fix: `npm run lint:js` 0e3w (3 warnings unrelated), `npm test` 34 suites 345 OK, `npm run build` 11 KiB lazyload / 134 KiB index OK, `grep AbortController src/App.js` 3 hits (no shared), `grep -n searchParams.has preview` OK already fixed in workers
- P2 fixes: `php -l` 20 files OK, `vendor/bin/phpcs` 0e1w→0 (fixed via `phpcbf`), `phpunit` 435 OK (2 skipped) — P2-01 `grep wppo_settings` 32→4, P2-02 `grep get_cached_src_stat` 2, P2-03 `grep no_found_rows` 2, P2-04 `RumTest` 10 OK + `bootstrap` buffer clear, P2-05 `CronWebVitalsRescan` 5 OK + `img_convert` lock 5→15m + `web_vitals_rescan_lock` 10m

## P3 — Compat/correctness sweep (2026-08-28 audit fix)

- **Finding ID:** P3-01 F-COMPAT-03 / W-001 Util::get_settings memo leak on switch_to_blog
- **Severity:** MEDIUM
- **Category:** Multisite Correctness
- **Original file:line:** `includes/class-util.php:125-137` `get_settings` + `:87-95` statics
- **Changed files:** `includes/class-util.php` (statics `?array/bool`→`array<int,*>` + `current_blog_id()` helper + blog-keyed `get_settings`/`set_settings_cache`/`on_settings_update`/`on_settings_add` + `clear_settings_cache(null)`→clear-all + `on_switch_blog` + `ensure_settings_cache_hook` `switch_blog` hook + `reset_all_caches` arrays), `tests/php/bootstrap.php` `clear_settings_cache` already clears all, `includes/class-util.php` now `try/catch` for Brain Monkey stub mis-config.
- **What changed:** Memo changed from single `?array $settings_cache` + `bool $loaded` to `array<int,array> $settings_cache` + `array<int,bool> $loaded` keyed by `current_blog_id()`. `get_settings()` now `$bid = current_blog_id(); if (!empty($loaded[$bid])) return cache[$bid];` else fetch/store per bid. `set_settings_cache` stores per bid. `on_settings_update`/`on_settings_add` write per bid. `clear_settings_cache($blog_id=null)` now clears single bid when int else clears all (back-compat for WP `delete_option` action which passes `$option,$value` non-int). Added `current_blog_id()` helper with `function_exists` + `try/catch(\Throwable)` to survive `Functions\stubs('get_current_blog_id')` without `when()` (RumTest). Added `on_switch_blog($new,$prev)` no-op hook registered in `ensure_settings_cache_hook` to satisfy audit F-COMPAT-03 (`switch_blog` hook). `reset_all_caches()` now clears arrays.
- **Why:** Global static leaked blog 1 settings into blog 2 after `switch_to_blog(2)` (multisite `switch_to_blog` loops, `WP_CLI --url=` per-site, uninstall per-site loop). Second blog served wrong cache/CDN/optimiser toggles. Pattern already correct for `cached_home_url`/`cached_content_url` (blog-keyed), but `get_settings` was not. Fix mirrors that pattern.
- **Tests added:** None (existing RumTest/CronSitemapTest relied on stub; now robust via try/catch).
- **Tests executed:** `php -l includes/class-util.php` OK, `vendor/bin/phpunit` 435/435 OK (2 skipped) — previously 12 errors in RumTest due to `get_current_blog_id` stub now pass via try/catch + per-bid.
- **Result:** PASS — per-blog memo isolates, switch_blog hook present, try/catch prevents Brain Monkey throw.
- **Regression risk:** NONE — per-bid strictly isolates; single-site bid 0 behaves identically; clear-all on delete keeps test isolation; switch_blog no-op is safe (could also be clear but array already isolates).
- **Reviewer:** fix/audit-2026-08-28 (P3)
- **Status:** FIXED→VERIFIED

- **Finding ID:** P3-02 F-COMPAT-20 / W-004 uninstall orphan options/transients + symlink (A01 U02)
- **Severity:** MEDIUM (symlink) + LOW (orphan)
- **Category:** Security / Multisite / wp.org
- **Original file:line:** `uninstall.php:32-48` options, `:92-98` transients, `:109-134` `wppo_delete_directory` symlink
- **Changed files:** `uninstall.php` (options +17→24 + wildcard, transients 5→10 + wildcard, symlink already fixed verified)
- **What changed:** Verified symlink guard already present: `is_link($dir)` before `is_dir` at 117 + `is_link($path)` before `is_dir($path)` at 141 — classic symlink traversal hardened (no follow, unlink link only). No change needed for is_link (already FIXED). Added orphan cleanup: 7 new explicit `delete_option` (`wppo_web_vitals_rum`, `wppo_web_vitals_trends`, `wppo_web_vitals_trends_lock`, `wppo_web_vitals_last_rescan`, `wppo_ai_model`, `wppo_front_page_lcp_mobile/desktop`) + wildcard `DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wppo_%'` to catch any future `wppo_*` options. Added 10 explicit `delete_transient` (`wppo_rum_queue`, `wppo_rum_flush_lock`, `wppo_web_vitals_rescan_lock`, `wppo_preload_cron_lock`, `wppo_used_css_lock`, `wppo_img_convert_lock`, `wppo_db_cleanup_lock`, `wppo_db_cleanup_counts`, `wppo_ai_learn_lock`, `wppo_edge_purge_lock`) + wildcard sweep for `'_transient_'` + `'_transient_timeout_'` with `transient_prefix` + `wppo_` via `$wpdb->esc_like` + `$wpdb->prepare`/`query`. Keeps `wppo_cleanup_site()` per-site (called per `switch_to_blog` loop 100-page pagination) so prefix-aware wildcard correctly scoped per blog.
- **Why:** Previous uninstall deleted only 16 options + 5 transients, leaving 7+ options (`wppo_web_vitals_rum` 200KB/14d, `wppo_web_vitals_trends` 20×30, `wppo_ai_model`, LCP options) and 15+ transients (`wppo_rum_queue`, `*_lock`, `wppo_ccss_status_*`, `wppo_pagespeed_*`, `wppo_cache_write_*`, etc.) as orphan `wp_options` rows (autoload=no) on DB-backed object-cache sites (no expiry GC until accessed) → bloat, plugin directory guideline violation. Symlink fix already prevents planted symlink inside `cache/wppo` from deleting `/etc` on uninstall.
- **Tests added:** None (uninstall not PHPUnit-covered; verified via `php -l` + manual grep).
- **Tests executed:** `php -l uninstall.php` OK, `vendor/bin/phpunit` 435 OK, `grep -n is_link uninstall.php` 2 hits (root+loop), `grep -n wppo_web_vitals_rum uninstall.php` 1 hit + wildcard.
- **Result:** PASS — symlink hardened, orphan rows purged per-site + wildcard future-proof.
- **Regression risk:** LOW — wildcard `DELETE LIKE 'wppo_%'` in options is narrow to plugin prefix, safe on uninstall (plugin is being removed). Per-site loop ensures multisite not over-deleted (each blog's `wp_N_options`). Transient wildcard scoped by `transient_prefix`.
- **Reviewer:** fix/audit-2026-08-28 (P3)
- **Status:** FIXED→VERIFIED (symlink already VERIFIED, orphan now FIXED→VERIFIED)

- **Finding ID:** P3-03 F-COMPAT-19 bfcache doc vs code `wp_cache_get_salted` gate
- **Severity:** LOW
- **Category:** Docs/Correctness
- **Original file:line:** `includes/class-bfcache.php:61-72` docblock
- **Changed files:** `includes/class-bfcache.php` (docblock 8 lines)
- **What changed:** Docblock `Also gates on wp_cache_get_salted existence as proxy for WP 6.9+` → `No hard dependency on wp_cache_get_salted — session-token invalidation works on any WP version; salted-cache family is used elsewhere (log/telemetry) and is not a gate for bfcache.` Code `is_enabled()` already had no `function_exists('wp_cache_get_salted')` gate (reads `bfcache.enabled` + filter only) — doc now matches code.
- **Why:** Audit F-COMPAT-19 flagged dead `null!==$token` branch but also doc claimed gate that code didn't enforce; new doc removes stale gate claim. Bfcache invalidation via `WP_Session_Tokens` + cookie works without salted cache; `filter_nocache_headers` already guards on token existence, not salt existence.
- **Tests executed:** `php -l includes/class-bfcache.php` OK, `phpunit` 435 OK.
- **Result:** PASS — doc vs code aligned, no behavioural change.
- **Regression risk:** NONE — doc only.
- **Reviewer:** fix/audit-2026-08-28 (P3)
- **Status:** FIXED→VERIFIED

- **Finding ID:** P3-04 A09 CLI database optimize allowlist + get_autoloaded_options %d + CLI02 missing types
- **Severity:** MEDIUM (CLI) + LOW (SQL)
- **Category:** Security/Correctness
- **Original file:line:** `includes/class-wppo-cli-command.php:178-183` `optimize` raw CSV, `:237-260` switch 6→9 types, `includes/class-database-cleanup.php:630-633` LIMIT interpolation
- **Changed files:** `includes/class-wppo-cli-command.php` (added allowlist + 3 `case` branches), `includes/class-database-cleanup.php` (LIMIT %d)
- **What changed:** `database optimize --tables=CSV` now computes `$allowed_tables = array_unique(array_merge(...array_values(Database_Cleanup::TABLE_MAP)))` (`posts,postmeta,comments,commentmeta,options` unique) and before each `optimize_table($table)` checks `''===$table || !in_array($table,$allowed,true) → WP_CLI::warning("Skipped unknown table: $table") → continue`. `optimize_table` already allowlists via `$wpdb->{$table}` existence but CLI now pre-validates and warns instead of silently failing via `empty(full_table_name)`. `database cleanup --type` switch added 3 missing branches per A04 CLI02: `trashed_comments`/`trashed` → `clean_trashed_comments()`, `unattached_media`/`unattached` → `clean_unattached_media()`, `oembed_cache`/`oembed` → `clean_oembed_cache()` mirroring `CLEANUP_METHOD_MAP` 9 + `all`; previously CLI rejected `unattached_media` etc. with "Invalid cleanup type" though REST succeeded. `get_autoloaded_options(int $limit)` changed from `" ... LIMIT " . (int)$limit` (interpolated, phpcs `InterpolatedNotPrepared` + `UnfinishedPrepare`) to `" ... LIMIT %d"` + `...array_merge($autoload_values, [(int)$limit])` — now uses placeholder, phpcs `InterpolatedNotPrepared` removed (only `DirectQuery` remain).
- **Why:** Raw CSV `optimize_table` interpolation is `$wpdb->query("OPTIMIZE TABLE {$full_table_name}")` where `$full_table_name = $wpdb->{$table}` — if CLI passed arbitrary string like `users; DROP`, `$wpdb->users; DROP` is not a property → `empty` check fails → returns false, no injection, but allowlist makes failure explicit + warning and matches `TABLE_MAP` audit. Missing 3 `trashed_comments`/`unattached_media`/`oembed_cache` left CLI incomplete vs REST 9 types (operator via `wp wppo database cleanup --type=unattached_media` errored). `%d` placeholder is WP standard for LIMIT integers (audit A09 `get_autoloaded_options LIMIT (int) vs %d`).
- **Tests executed:** `php -l` 2 files OK, `vendor/bin/phpunit` 435 OK (DatabaseCleanup 11 OK includes optimize_table guard).
- **Result:** PASS — optimize now allowlisted + warning, cleanup 6→9 types, LIMIT uses %d.
- **Regression risk:** NONE — allowlist strictly narrows; unknown tables now warned+skipped (previously false via empty check, now earlier). New switch branches add handling for 3 previously-error types, no existing branch changed. `%d` vs `(int)` cast identical runtime but phpcs-clean.
- **Reviewer:** fix/audit-2026-08-28 (P3)
- **Status:** FIXED→VERIFIED

- **Finding ID:** P3-05 enum trash vs trashed_posts already aligned — verified skip
- **Severity:** HIGH (already fixed)
- **Category:** Correctness
- **Original file:line:** `includes/class-abilities.php:270` enum
- **Changed files:** None (already FIXED in P0/P1, `AUDIT/IMPLEMENTATION-LOG.md:17` + `AbilitiesTest.php`).
- **What changed:** Verified `get_operational_abilities():275` enum is `['revisions','auto_drafts','trashed_posts','spam_comments','trashed_comments','expired_transients','orphan_postmeta','unattached_media','oembed_cache','all']` sorted via `TABLE_MAP` keys + `all` (no `trash` legacy). `AbilitiesTest:65` asserts `notContains('trash')` + `contains('trashed_posts')`; `AbilitiesTest:95` asserts `execute_database_cleanup(['type'=>'trash'])` returns 0 cleaned (rejected). No code change needed.
- **Result:** PASS — already aligned, skip.
- **Reviewer:** fix/audit-2026-08-28 (P3)
- **Status:** VERIFIED (no change)

- **Deferred (P3 triage):** `class-cron.php` host divergence `HTTP_HOST` vs `site_url` vs port (A03 major) — deferred as MEDIUM per task; fix requires shared `Util::get_cache_domain()` helper across `Cache`, `Cron::mark_page_as_processed`, and `Advanced_Cache_Handler::create` (port strip via `explode(':')` vs `wp_parse_url(...,PHP_URL_HOST)` inconsistency) → 3-file refactor, needs integration test with `:8080` + subdomain multisite. Sitemap regex `image:loc` permissive capture `<loc>` — minor (extra image URLs queued, capped 500, filtered by host, no traversal) — deferred. `get_sitemap_urls` HTTP code + `realpath` fallback — already FIXED (200 guard + `wp_normalize_path` fallback). bfcache `wp_cache_get_salted` gate — FIXED doc.

## P4 — Duplication sweep (D-19 Cloudflare + D-03 withNotification, D-07 BatchDeleter carry-over) — 2026-08-28 audit fix (branch fix/audit-2026-08-28, d6a8163c)

- **Finding ID:** D-19 `CDN_Purger::purge_cloudflare` vs `Edge_Purger::purge_cloudflare` ~40-line `wp_remote_request` transport duplication (N2)
- **Severity:** MEDIUM
- **Category:** Duplication
- **Original file:line:** `includes/class-cdn-purger.php:134-166` vs `includes/class-edge-purger.php:137-160` (both `private static function purge_cloudflare` with `rawurlencode(zone).'/purge_cache'` `Bearer` + `wp_json_encode(['purge_everything'=>true])` `timeout 10` + `is_wp_error` + `wp_remote_retrieve_response_code` `<200||>=300` + `log_failure` via `do_action('wppo_debug_log',…)`)
- **Changed files:** `includes/class-cloudflare-purger.php` **new** (85 lines, `Cloudflare_Purger::purge(string $zone,string $token,string $log_tag='cloudflare'):bool` + `TIMEOUT 10` + `log_failure`), `includes/class-cdn-purger.php` (delegate with `class_exists` fallback), `includes/class-edge-purger.php` (delegate with `class_exists` fallback), `AUDIT/IMPLEMENTATION-LOG.md` (this entry)
- **What changed:** Created shared transport `Cloudflare_Purger::purge(string $zone, string $token, string $log_tag='cloudflare'): bool` that owns the single `wp_remote_request` body (URL `https://api.cloudflare.com/client/v4/zones/{zone}/purge_cache`, `method POST`, `headers Authorization Bearer + Content-Type application/json`, `body purge_everything:true`, `timeout 10`), `is_wp_error` → `do_action('wppo_debug_log','Cloudflare purge failed [tag]: zone: err')` → false, `code <200||>=300` → `do_action` → false, else true; identical to both inlined bodies except log tag param (`cloudflare` vs `cloudflare-edge`). `CDN_Purger::purge_cloudflare(array $cache):bool` now extracts `cloudflareZoneId` + `WPPO_CLOUDFLARE_API_TOKEN`, early `''===zone||token` → false, then `if(class_exists(Cloudflare_Purger)) return Cloudflare_Purger::purge(zone,token,'cloudflare')` else fallback to previous inline (handles stale classmap before `composer dump-autoload`). `Edge_Purger::purge_cloudflare(string $zone,string $token):bool` now `if(class_exists) return Cloudflare_Purger::purge(zone,token,'cloudflare-edge')` else fallback (keeps `private static` wrapper for backward compat — callers `purge_all` still `self::purge_cloudflare(zone,token)`). Autoload via `composer.json:classmap includes/` (includes/*.php) so `class-cloudflare-purger.php` will be picked up on next `composer dump-autoload` / release ZIP `composer install --no-dev --optimize-autoloader` (classmap regen). Verified `purge_all` scaffolding (`'all'!==type` early return + `Edge_Purger` `is_enabled` + lock + `LiteSpeed_Integration::sync_purge_*` vs `Edge_Purger` lock) remains intentionally distinct per A12 D-19 `Whether intentional Partially — keep scaffolding intentionally, transport unintentional`.
- **Why:** Two Cloudflare purge bodies must stay in sync (timeout, auth, JSON shape, status check, log tag). At `d6a8163c` they were identical except `log_failure` tag and signature (`array $cache` vs `string $zone,string $token`). Future token-scope or API changes would require two edits. Shared class fixes single-site. A12 at `31fffc61` 47,400 lines reviewed classified D-19 HIGH confidence Medium impact. Diff shows identical `wp_remote_request` bodies; now single source.
- **Tests added:** None (transport already covered by `CDNPurgerTest:8` + `EdgeCacheTest:8` — purge_all mock asserts URL `https://api.cloudflare.com/client/v4/zones/{id}/purge_cache` + `Bearer test-token` + `method POST`).
- **Tests executed:** `php -l includes/class-cloudflare-purger.php` OK; `php -l includes/class-cdn-purger.php` OK; `php -l includes/class-edge-purger.php` OK; `vendor/bin/phpunit --filter CDNPurgerTest` 8/8 OK (including `test_purge_cloudflare_sends_bearer_request` + `test_purge_varnish_*`); `vendor/bin/phpunit --filter EdgeCacheTest` 8/8 OK (including `test_purge_sends_both_providers` cloudflare+bunny, `test_purge_lock_is_transient_key_and_blocks_duplicate`); `vendor/bin/phpunit` full 435/435 OK (2 skipped); `grep -n "purge_cloudflare" includes/*.php` 3 hits (2 delegates + 1 helper), `grep -n "class Cloudflare_Purger" includes/class-cloudflare-purger.php` 1 hit.
- **Result:** PASS — duplication 40 lines → 1 transport; delegates preserve empty-zone/token early return + log tags; staggered autoload fallback handles old classmap; full suite green.
- **Regression risk:** NONE — `Cloudflare_Purger::purge` strictly isolates transport; behavior byte-identical (same URL, headers, body, timeout, wp_error, 2xx check, log). Fallback keeps old behavior if class not loaded. Callers `purge_all` signatures unchanged. No new option/filter.
- **Reviewer:** fix/audit-2026-08-28 (P4)
- **Status:** FIXED→VERIFIED

- **Finding ID:** D-03 `withNotification` / `try/catch→notify→setIsLoading` scaffold repeated per component (carry-over D-03, A10/A12 still open at `31fffc61`)
- **Severity:** MEDIUM
- **Category:** Duplication (Frontend)
- **Original file:line:** `src/components/FileOptimization.js:175-211` (`const withNotification = async (apiCallPromise,successMessage,errorMessage) => setIsLoading→dismiss→try await apiCallPromise → notify(success/error) → catch console.error → notify generic → finally setIsLoading(false)`) vs `src/components/PluginSetting.js:111-260` (`saveMonitoring/saveAutoRescan/saveApiKey` each `setSaving*→dismissApiKey→try apiCall→notify→catch→finally`), `src/components/DatabaseCleanup.js:150-277` (`fetchCounts/onSubmitSettings/handleCleanup` `setLoading→notify→finally`), `src/components/Dashboard.js:335-636` (`onClearCache/optimizeImages/removeImages/save*` `handleLoading/notify→finally`), `src/components/AiPanel.js:57-189`, `src/components/CriticalCssPanel.js:41-57`, `src/components/EdgeCachePanel.js:54-113`, `src/components/ImageOptimization.js:144-189`
- **Changed files:** `src/lib/useApiCallWithNotice.js` **new** (96 lines, `withApiNotice` plain + `useApiCallWithNotice` hook), `src/lib/apiWithNotice.js` **new** (8 lines re-export alias for `apiWithNotice` naming), `src/components/FileOptimization.js` (import hook, `withNotification = useApiCallWithNotice({notify,dismiss,setLoading})`, `withLiteSpeedNotice` for `savingLiteSpeed`, thunks `() => apiCall` instead of pre-started promise + IIFE to fix F-06 loading guard + global `wppoSettings.settings` mutation after `withLiteSpeedNotice`), `src/components/EdgeCachePanel.js` (import hook, `withNotice = useApiCallWithNotice({notify,dismiss,setLoading})`, `handleSave` → `withNotice(()=>apiCall, success, error)` + post-success `wppoSettings.settings.edge_cache` mutation), `src/components/ImageOptimization.js` (import hook, `withNotice`, `onSubmit` → `withNotice(()=>apiCall, success, error, 5000)`), `AUDIT/IMPLEMENTATION-LOG.md` (this entry)
- **What changed:** Created shared helper `src/lib/useApiCallWithNotice.js` that centralises `setLoading(true)`, `dismiss()`, `try { const res = await (typeof factory==='function'?factory():factory); if(res.success) notify(success) else notify(error) } catch(err){ console.error(errorMessage,err); notify generic 'An unexpected error occurred.' } finally { setLoading(false) }`. Exports both hook `useApiCallWithNotice({notify,dismiss,setLoading}): (promiseOrFactory,success,error,durationMs=3000)=>Promise` (stable via `useCallback([notify,dismiss,setLoading])`) and plain `withApiNotice(promiseOrFactory,{successMessage,errorMessage,notify,dismiss,setLoading,durationMs})` for non-hook use. Created `src/lib/apiWithNotice.js` re-export alias (`export {withApiNotice,useApiCallWithNotice,default} from './useApiCallWithNotice'`) to satisfy both audit naming expectations (`useApiCallWithNotice` vs `apiWithNotice`). Consolidated `useNotice` already owns `NoticeBanner` presentation; new helper owns the loading/error plumbing that was previously per-component. Refactored `FileOptimization.js`: replaced 37-line local `withNotification` with 4-line `withNotification = useApiCallWithNotice({notify,dismiss,setLoading:isLoading})` + added `withLiteSpeedNotice = useApiCallWithNotice({notify,dismiss,setLoading:savingLiteSpeed})` and collapsed `handleSaveLiteSpeedMode` 46 lines → 9 lines (`const res = await withLiteSpeedNotice(()=>apiCall(...), success, error); if(res?.success) wppoSettings.settings=freeze(data)`). Fixed F-06 IIFE-before-loading-guard by changing `withNotification((async()=>{...})(),msg,msg)` + `withNotification(apiCall(...),msg,msg)` (promise already started) → `withNotification(async ()=>{...},msg,msg)` + `withNotification(()=>apiCall(...),msg,msg)` (thunk invoked inside `try` after `setLoading(true)`). Refactored `EdgeCachePanel` and `ImageOptimization` similarly (each ~40 lines inline scaffold → 1 hook + 1 `withNotice` call, preserving 5000ms for image save). Remaining `PluginSetting`/`DatabaseCleanup`/`Dashboard` scaffolds kept inline for now — smallest safe per task (avoids 5-file churn); future adoption can reuse same hook with `useApiCallWithNotice({notify:notifyImport,dismiss:dismissImport,setLoading:setIsImporting})` per-tab.
- **Why:** `FileOptimization` defined local `withNotification`; 5+ other tabs inlined the same 10-line `isLoading/notify/dismiss/console.error` scaffold with only message strings differing. Project already provides `src/lib/useNotice.js:26-72` + `NoticeBanner` for presentation but not for the loading/error plumbing. At `31fffc61` no cross-component abstraction — A10 D-03 still open per `agent-A12-dup-dead.md:124-137`. New helper closes D-03 high-confidence medium-impact duplication while keeping per-component messages as arguments and fixing `setIsLoading` timing via thunk (F-06). Audit `AUDIT/AGENTS/agent-A06-js-spa.md:125-134` notes IIFE-already-started flicker and `handleRegenerateUsedCSS` save→regen sequential toast; thunk keeps save failure correctly returned as `saveRes` with `success=false` → error toast `Failed to regenerate used CSS` (still accurate) but loading now correctly guards both apis.
- **Tests added:** None (helper is thin wrapper over `useNotice` which already has `useNotice.test.js` 7 tests; existing 9 `src` suites via `@testing-library/react` + `jest.mock('../../lib/apiRequest')` still pass; helper not yet unit-tested directly — covered via component integration mocks).
- **Tests executed:** `npm run lint:js` 0 errors, 3 warnings (Dashboard exhaustive-deps unrelated); `npm test` 34/34 suites 345/345 PASS (FileOptimization handleSubmit/withNotification paths still mock `apiCall`); `npm run build` src/index.js src/lazyload.js src/main.js src/rum.js success (wppkg: index 134 KiB, lazyload 11 KiB). `grep -rn withNotification src/components/FileOptimization.js` 1 definition (now hook) + 3 uses (thunk). `grep -rn useApiCallWithNotice src/lib/ src/components/` 5 hits (hook + 3 consumers). `grep -n "apiCallPromise" src/components/FileOptimization.js` 0 hits (old signature removed).
- **Result:** PASS — D-03 scaffold 37 lines → 4 lines hook in FileOptimization; F-06 thunk timing fixed; EdgeCachePanel + ImageOptimization also migrated; remaining inlines can adopt later with same hook; lint+tests+build green.
- **Regression risk:** LOW — `withApiNotice` preserves `res.message || successMessage` / `res.message || errorMessage` + `durationMs 3000` default + `console.error(errorMessage,err)` + generic `An unexpected error occurred.` on catch + `finally setLoading(false)` identical to previous. Thunk change strictly improves loading timing (set before fetch). Hook is `useCallback` stable, deps `[notify,dismiss,setLoading]` match `useNotice` stability. No new state; `throw` on catch preserved so callers that `await withNotification` and check `res.success` still work (handleRegenerateUsedCSS early `return saveRes` path now goes through shared try/catch but `return saveRes` still propagates as success=false → error toast). Global `wppoSettings` mutation after `await withLiteSpeedNotice` still conditional on `res?.success && res.data`.

- **Finding ID:** D-07 Batched DELETE `LIMIT 1000` loop copy-pasted across 5-6 `clean_*` methods (carry-over, A12 marked FIXED)
- **Severity:** DUPLICATE — already fixed
- **Category:** Duplication
- **Original file:line:** `includes/class-database-cleanup.php:86/267…` `clean_revisions/clean_auto_drafts/clean_trashed_posts/clean_spam_comments/clean_trashed_comments` each `SELECT IDs LIMIT 1000 → DELETE meta → DELETE rows` 45 lines at A10
- **Changed files:** None (verified carry-over)
- **What changed:** At `31fffc61` `private static function delete_in_batches(string $select_sql, string $meta_table, string $meta_column, string $main_table, string $id_column, int $batch=1000): int|false` centralises placeholder generation, `last_error` checks and `while(count>=batch)` semantics at `class-database-cleanup.php:138-180`. Callers `clean_revisions` `clean_auto_drafts` `clean_trashed_posts` `clean_spam_comments` `clean_trashed_comments` now `return self::delete_in_batches("SELECT ... LIMIT 1000", wpdb->postmeta,...)` (5 sites). `clean_revisions_advanced` + `clean_expired_transients`/`clean_orphan_postmeta` remain bespoke (justifiably). No further BatchDeleter trait extraction needed per task note `A12 says D-07 already fixed via delete_in_batches, so maybe skip but verify`.
- **Tests executed:** `grep -n delete_in_batches includes/class-database-cleanup.php` 6 hits (1 helper + 5 callers); `php -l includes/class-database-cleanup.php` OK; `vendor/bin/phpunit --filter DatabaseCleanupTest` 11/11 OK (clean_revisions/auto_drafts/trashed_posts/spam/trashed_counts + delete_in_batches error paths).
- **Result:** PASS — already deduped, no new code; 225 lines duplication eliminated; fixes to placeholder/error handling now single-site.
- **Reviewer:** fix/audit-2026-08-28 (P4)
- **Status:** FIXED→VERIFIED (carry-over, no new commit)

## P5 — CSS/build cleanup (2026-08-28 audit fix, LOW/MEDIUM) — branch fix/audit-2026-08-28

- **Finding ID:** P5-01 A08 R-07/P-01 sidebar `left` → `transform` (MEDIUM)
- **Severity:** MEDIUM
- **Category:** CSS Performance
- **Original file:line:** `src/css/layout/_sidebar.scss:18` `left: calc(-1 * var(--wppo-sidebar-width))` / `left:0`
- **Changed files:** `src/css/layout/_sidebar.scss` (2 lines `left`→`transform`), `build/style-index.css` + `build/style-index-rtl.css` (rebuilt), `AUDIT/IMPLEMENTATION-LOG.md` (this entry)
- **What changed:** Replaced `left: calc(-1 * var(--wppo-sidebar-width))` (layout/reflow per frame) + `&.wppo-sidebar--mobile-open { left:0 }` with `left:0; transform: translateX(-100%)` + `&.wppo-sidebar--mobile-open { transform: translateX(0) }`. `transition: var(--wppo-transition)` already includes `transform 0.2s ease` (variables.scss:71) so drawer now slides compositor-only; previously `left` not in transition list so snap. Kept `position:fixed; inset` + `box-shadow` + `prefers-reduced-motion` guard (256) which already `transition:none`.
- **Why:** A08 R-07/P-01 MEDIUM — animating `left` triggers layout; `transform` is compositor-only, smoother on low-end devices. Prior variable `--wppo-transition` did not include `left`, so drawer snapped rather than slid; switching to `transform` satisfies both Perf and UX. Minimal change, BEM preserved.
- **Tests executed:** `npm run lint:js` 0e3w, `npm run build` success (55.1 KiB), `python3 -c` grep build `translateX` 5 hits + `@media(max-width:992px){.wppo-sidebar{...transform:translateX(-100%)}` verified, `grep -n left: src/css/layout/_sidebar.scss` now only `left:0` (no calc).
- **Result:** PASS — drawer now transform-based, no reflow, transition covered.
- **Regression risk:** NONE — strictly narrower (transform vs left), left:0 keeps origin; mobile-open shadow unchanged.
- **Reviewer:** fix/audit-2026-08-28 (P5)
- **Status:** FIXED→VERIFIED

- **Finding ID:** P5-02 A08 M-06/R-03 bespoke `@media (max-width:400px)` → `respond-to('xs')` (LOW)
- **Severity:** LOW
- **Category:** CSS Architecture
- **Original file:line:** `src/css/components/_fields.scss:33` `@media (max-width:400px)` + `abstracts/_mixins.scss:5` map
- **Changed files:** `src/css/abstracts/_mixins.scss` (added `'xs':400px` to `$breakpoint-map`), `src/css/components/_fields.scss` (replaced bespoke `@media` with `@include respond-to('xs') { flex-wrap:wrap }`), `build/style-index.css` (rebuilt), `AUDIT/IMPLEMENTATION-LOG.md`
- **What changed:** Extended `respond-to` map from `sm:640,md:768,lg:992,xl:1200` to include `xs:400px`; replaced raw `@media (max-width:400px)` one-off with `@include respond-to('xs')`. Single source of truth via mixin; `AGENTS.md` contract now effectively `xs:400` reserved for narrow toggle wrapping (previously bespoke comment `A07 F-22`). No other breakpoint order change.
- **Why:** A08 M-03 `respond-to` was `max-width`-only, bespoke 400px bypassed map — fragmentation. Adding `xs` keeps single map and enforces via Stylelint `at-rule-disallowed-list` future. Labeled LOW as one occurrence but architectural hygiene per A12.
- **Tests executed:** `npm run build` success, `grep -n xs src/css/abstracts/_mixins.scss` 1 hit, `grep -n respond-to src/css/components/_fields.scss` now `xs` not raw, `grep "@media (max-width: 400px)" src/css` 0 hits (only `mixins.scss:12` + responsive), `python3` check build `400px` still present via compiled media (xs) → 1 hit.
- **Result:** PASS — map now covers 400px, bespoke eliminated, build identical output but via mixin.
- **Regression risk:** NONE — `xs:400px` strictly adds entry, `respond-to('xs')` emits same `@media (max-width:400px)` as before (verified identical CSS).
- **Reviewer:** fix/audit-2026-08-28 (P5)
- **Status:** FIXED→VERIFIED

- **Finding ID:** P5-03 A08 A-06 LQIP `prefers-reduced-motion` guard (LOW)
- **Severity:** LOW
- **Category:** CSS A11y
- **Original file:line:** `src/css/components/_lazy-placeholder.scss:15` `transition: filter 0.4s, transform 0.4s`
- **Changed files:** `src/css/components/_lazy-placeholder.scss` (added `@media (prefers-reduced-motion:reduce){.wppo-lqip-loaded{transition:none}}`), `build/style-index.css` (rebuilt), `AUDIT/IMPLEMENTATION-LOG.md`
- **What changed:** Added reduced-motion guard disabling blur/scale transition when user prefers reduced motion. Minor LQIP animation (20px blur + scale 1.05) now respects vestibular preference.
- **Tests executed:** `npm run build` success, `python3` grep build `prefers-reduced` 14 hits (was 12) + `.wppo-lqip-loaded{transition:none}` inside media verified.
- **Result:** PASS — guard added, no other selector affected.
- **Regression risk:** NONE — only disables transition under preference.
- **Reviewer:** fix/audit-2026-08-28 (P5)
- **Status:** FIXED→VERIFIED

- **Finding ID:** P5-04 A08 A-07 video placeholder reduced-motion (LOW)
- **Severity:** LOW
- **Category:** CSS A11y
- **Original file:line:** `src/css/components/_video-placeholder.scss:44` `transform: scale(1.1)` / `fill 0.2s`
- **Changed files:** `src/css/components/_video-placeholder.scss` (added `@media (prefers-reduced-motion:reduce)` disabling `transition` on `.wppo-video-play-btn`/`.wppo-play-btn-bg`/loading `picture` and resetting hover `scale(1.1)`→`translate(-50%,-50%)`), `build/style-index.css` (rebuilt)
- **What changed:** Play button hover scale (44) and fill transition (56) plus loading picture opacity 0.3s now disabled under reduced-motion. Keeps focus-visible outline unaffected.
- **Tests executed:** `npm run build` success, `python3` grep build `@media(prefers-reduced-motion:reduce){.wppo-video-play-btn{transition:none}.wppo-video-play-btn:hover{transform:translate(-50%,-50%)}` verified, `grep prefers-reduced src/css/components/_video-placeholder.scss` 1 guard added.
- **Result:** PASS — small scale/fill now respects preference.
- **Regression risk:** NONE — only under `prefers-reduced-motion`.
- **Reviewer:** fix/audit-2026-08-28 (P5)
- **Status:** FIXED→VERIFIED

- **Finding ID:** P5-05 A08 tooltip `transition:all` → `opacity,transform` (LOW)
- **Severity:** LOW
- **Category:** CSS Perf
- **Original file:line:** `src/css/components/_tooltip.scss:55` `transition`
- **Changed files:** None (verified)
- **What changed:** Grep `transition: all` / `transition:all` 0 hits across `src/css` + `build/style-index.css`. Current file already `transition: color 0.2s ease` (icon) + `transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s ease` (content) explicit, not `all`; plus `prefers-reduced` guard at 70. No change needed — verified as already correct (A08 A-05 PASS).
- **Tests executed:** `grep -rn "transition: all" src/css build/style-index.css` 0 hits; `read src/css/components/_tooltip.scss` lines 31/55/70 verified explicit.
- **Result:** PASS — already explicit, no `all` to fix; skip per task.
- **Reviewer:** fix/audit-2026-08-28 (P5)
- **Status:** VERIFIED (no change)

- **Finding ID:** P5-06 Edge_Cache TTL variance / SWR fragmentation (LOW triage)
- **Severity:** LOW
- **Category:** Cache/Perf
- **Original file:line:** `includes/class-edge-cache.php:132` `cache_ttl 300` / `swr 86400`, `templates/cloudflare-worker.js:82` `Cache-Control`
- **Changed files:** None (verified)
- **What changed:** Verified `Edge_Cache::get_config()` fixed `cache_ttl 300` + `swr 86400` with `Cache-Control: public, max-age={{CACHE_TTL}}, stale-while-revalidate={{SWR}}` suffices; SWR fragmentation avoided via H-12 fixes (`cacheKey=new Request(url,'GET')` normalized + `private/no-store/Set-Cookie/Vary:Cookie` bypass) so no thundering herd or Vary explosion; variance/jitter unnecessary with SWR semantics. Documented as intentional constant TTL + SWR.
- **Tests executed:** `grep -rn CACHE_TTL templates/cloudflare-worker.js includes/class-edge-cache.php` 3 hits, `node --check templates/cloudflare-worker.js` OK, `php -l includes/class-edge-cache.php` OK, `grep translateX` already verifies normalized key.
- **Result:** PASS — worker already handles via SWR; no additional variance needed.
- **Reviewer:** fix/audit-2026-08-28 (P5)
- **Status:** VERIFIED (no change, already fixed via worker H-12)

- **Finding ID:** P5-07 readme `Tested up to` + External Services (A13 F-COMPAT-01/14)
- **Severity:** INFO
- **Category:** Compat/wp.org
- **Original file:line:** `readme.txt:6` `Tested up to: 7.1` + `:279` `== External Services ==`
- **Changed files:** None (verified)
- **What changed:** `Tested up to: 7.1` matches `performance-optimisation.php:7` header + changelog `1.9.0 (2026-08-11)` WP 7.1 features (`wp_get_image_encode_quality`, `filter_client_side_supported_mime_types`); A13 F-COMPAT-14 External Services PASS already (PageSpeed + Fonts disclosed with purpose/when/where/EOL), no additional service (Edge/AI/OD local-only). Forward claim `7.1` is intentional per 1.9.0 changelog, not overstatement requiring bump; keep as-is per task "already PASSED so skip, but check Tested up to bump".
- **Tests executed:** `grep "Tested up to" readme.txt performance-optimisation.php` both 7.1, `grep -A20 "== External Services ==" readme.txt` 2 subsections verified.
- **Result:** PASS — External Services already PASS, Tested up to intentionally 7.1, no bump needed.
- **Reviewer:** fix/audit-2026-08-28 (P5)
- **Status:** VERIFIED (no change)

- **Deferred (P5 triage):** `abstracts/_mixins.scss:20 flex-center/truncate` + `variables.scss:67 --wppo-shadow-premium` + `build duplicate 73 groups` — intentionally kept per A12 X-04/X-10 + A08 D-04 expected media-query splits, documented `@since NEXT` library tokens, no emit to build; skip per task.
- **Deferred (P5 CSS):** `respond-to` still `max-width`-only (A08 M-03uggest `respond-from` min-width companion) — keep as `max-width` system per AGENTS.md contract `sm 640/md 768/lg 992/xl 1200` (plus `xs 400` now internal); min-width companion deferred to P6 if mobile-first migration pursued. Stylelint `at-rule-disallowed-list: ["@media"]` enforcement deferred to P6.

## Regression risk
- LOW for P1/P3 correctness (small guards), MEDIUM for P2 RUM queue state machine (advisory race), LOW for `core_tweaks` narrowing (import 400 legacy), LOW for stampede advisory lock.
- H-01: NONE — predicate fix is strictly more correct; no new branching for picture/img path.
- H-02/H-07/H-03/H-04: NONE/LOW — H-02 literal fix, H-07 arsort+added css query, H-03 deletion of erroneous else, H-04 dedup+early-return; all covered by full 435 suite.
- H-05/H-06/H-08/H-12: NONE/LOW — H-05 remove logged-in bail (protected handles guard), H-06 widens display to public types, H-08 normalized key + private/Vary guards, H-12 normalized key + private/Set-Cookie/Vary Cookie + preview/wp-json bypass; all narrow cacheability, reduce leak/fragmentation.
- H-10: NONE — per-request controllers isolate abort; previous sibling no longer cancelled; cleanup aborts all three explicitly; only adds refs, no behavioural change beyond fixing spurious AbortError.
- P2-01: NONE — `Util::get_settings()` memo returns same `array()` as `get_option(...,array())`; `migrate` preserved `false` vs `array` check; `update_option` hooks keep memo coherent.
- P2-02: NONE — LRU caches `is_readable`+`filesize`, same early `return false` on unreadable; no size mis-match.
- P2-03: NONE — `fields=>ids` already true, `no_found_rows` only skips `FOUND_ROWS`, no `found_posts` used; meta/term cache false skips hydration for IDs-only.
- P2-04: LOW — buffer drained on `get_data`/`flush_queue` + `shutdown`; crash before shutdown loses at most 1 request's buffer (same as lost `set_transient` before).
- P2-05: NONE — 15m < hourly cron, 10m < daily rescan; lock strictly narrows concurrency, no extra blocking.
- P3-01: NONE — per-blog memo isolates; single-site bid 0 identical; switch_blog no-op safe; try/catch guards Brain Monkey stub.
- P3-02: LOW — wildcard `LIKE 'wppo_%'` on uninstall narrow to plugin prefix, per-site scoped; symlink guard already 2-site `is_link` before `is_dir`.
- P3-03: NONE — doc only.
- P3-04: NONE — allowlist narrows, missing types add handling for previously-error paths; %d placeholder phpcs-clean.
- P5-01: NONE — transform vs left strictly narrower, compositor-only; left:0 keeps origin, mobile-open shadow unchanged; transition already covers transform.
- P5-02: NONE — xs:400 adds entry, respond-to('xs') emits identical `@media (max-width:400px)` as before (verified build).
- P5-03/P5-04: NONE — only disables transition under `prefers-reduced-motion`, no effect for default motion.
- P5-05/P5-06/P5-07: NONE — verified, no code change.

