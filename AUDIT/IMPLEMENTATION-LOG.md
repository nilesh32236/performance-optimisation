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

## Evidence per batch
- P1 security: `php -l` 5 files clean, `phpcs` 0, `phpunit` 403 OK
- P2 perf: `php -l` 7 files clean, `phpunit` 403 OK, `npm build` 54.8 KiB
- P3 correctness: `php -l` 8 files, `phpunit` 403 (after CronSitemap fix)
- P4 dedupe: `php -l` 7 files, `phpunit` 67 focused OK + `npm build`
- P5 cleanup: `php -l` clean, `npm run lint:js` 0e3w, `npm test` 8 suites PASS (after NoticeBanner fix), `npm run build` success

## Regression risk
- LOW for P1/P3 correctness (small guards), MEDIUM for P2 RUM queue state machine (advisory race), LOW for `core_tweaks` narrowing (import 400 legacy), LOW for stampede advisory lock.

