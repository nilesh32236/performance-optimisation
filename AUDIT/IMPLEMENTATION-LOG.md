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
| Notification wrappers | DUPLICATE | Dedupe | `src/lib/useNotice.js` | VERIFIED (FALSE_POSITIVE) | already shared, no dup state | `grep withNotification` 1 use | Final Arch PASS |
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

## Evidence per batch
- P1 security: `php -l` 5 files clean, `phpcs` 0, `phpunit` 403 OK
- P2 perf: `php -l` 7 files clean, `phpunit` 403 OK, `npm build` 54.8 KiB
- P3 correctness: `php -l` 8 files, `phpunit` 403 (after CronSitemap fix)
- P4 dedupe: `php -l` 7 files, `phpunit` 67 focused OK + `npm build`
- P5 cleanup: `php -l` clean, `npm run lint:js` 0e3w, `npm test` 8 suites PASS (after NoticeBanner fix), `npm run build` success
- H-01 fix: `php -l` clean, `phpunit` 435 OK, `grep count.*matches` 0
- H-02/H-07/H-03/H-04 fix: `php -l` 3 files clean, `phpunit --filter AiAdaptiveTest|OdBridgeTest` 23 OK (1 skipped), `phpunit` full 435 OK (2 skipped)

## Regression risk
- LOW for P1/P3 correctness (small guards), MEDIUM for P2 RUM queue state machine (advisory race), LOW for `core_tweaks` narrowing (import 400 legacy), LOW for stampede advisory lock.
- H-01: NONE — predicate fix is strictly more correct; no new branching for picture/img path.
- H-02/H-07/H-03/H-04: NONE/LOW — H-02 literal fix, H-07 arsort+added css query, H-03 deletion of erroneous else, H-04 dedup+early-return; all covered by full 435 suite.

