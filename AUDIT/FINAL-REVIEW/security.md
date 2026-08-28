# FINAL SECURITY REVIEW — Post-Fix Verification

**Reviewer:** Final Security Agent (independent)  
**Date:** 2026-08-28  
**Workspace:** `/var/www/nileshportfolio.duckdns.org/wp-content/plugins/performance-optimisation`  
**Branch:** `fix/deep-dashboard-2026`  
**Git range inspected:** `origin/master (c127b865)` → `HEAD (9ce35209)` ; detailed patch `HEAD~1..HEAD` (47 files) + unstaged  
**Method:** Read every changed file directly (`Read`), diffed `git diff HEAD~1`, `git diff origin/master`, traced `$_SERVER`/`$_GET`/`$_POST` sources, `$wpdb->prepare` usage, `WP_Filesystem` writes, `wp_remote_get` destinations, `permission_callback`, `update_option`, `hash_equals`, `is_link`. No trust in implementation-agent claims.

---

## 1. Scope

- **PHP surface:** 25 files in `includes/`, `uninstall.php`, `templates/object-cache.php` (unchanged), `performance-optimisation.php`
- **Changed files audited (47):** `includes/class-main.php`, `includes/class-google-fonts.php`, `includes/class-rum.php`, `includes/class-rest.php`, `includes/class-cache.php`, `includes/class-database-cleanup.php`, `includes/class-util.php`, `includes/class-cron.php`, `includes/class-advanced-cache-handler.php`, `includes/class-abilities.php`, `includes/class-image-optimisation.php`, `includes/class-used-css.php`, `includes/class-critical-css.php`, `includes/class-wppo-cli-command.php`, `uninstall.php`, plus JS `src/components/*`, `src/lib/litespeed.js`, `src/css/*`, `tests/php/*`
- **Threats inspected:** authz (caps/nonces), authn, SSRF, path traversal/symlink, SQLi, XSS, CSRF, RCE via file write, DoS via option bloat, information disclosure, multisite isolation.

---

## 2. Fixes Verified (per original finding, with file:line evidence)

### 2.1 A-AUTH-02 — Admin-bar information disclosure (MEDIUM)

**Original:** `includes/class-main.php:466` registered `add_setting_to_admin_bar` on `admin_bar_menu` unconditionally; callback added 2 nodes (`wppo_setting`, clear-cache children) without `current_user_can('manage_options')`. Subscribers with admin bar saw links.

**Fix verified — PASS:**
- `includes/class-main.php:1727-1734` now gates inside callback:
  ```php
  public function add_setting_to_admin_bar( $wp_admin_bar ) {
      if ( ! current_user_can( 'manage_options' ) ) {
          return;
      }
      $wp_admin_bar->add_node( ... );
  ```
- Docblock expanded `@since NEXT Added manage_options capability check.` with defence-in-depth note that REST `permission_callback` also requires `manage_options + X-WP-Nonce`.
- **Functionality preserved:** Admins still see both nodes; non-admins see none. REST handlers unchanged, so 403 on direct POST remains. No regression — hook still registered for all but early-returns cheaply. Alternative gate at `add_action` registration was not chosen; either location is correct, this one is simpler and avoids capability timing issues on `init`.

### 2.2 A02-009 — Google Fonts host substring bypass → SSRF (MEDIUM)

**Original:** `includes/class-google-fonts.php` used `strpos($url,'fonts.googleapis.com')` in `process_style_tag:109`, `normalize_google_fonts_url:261`, and `process_buffer` regex. Hosts like `https://evil.com/fonts.googleapis.com/css` or `https://fonts.googleapis.com.evil.com/css` passed, causing `wp_remote_get` to an attacker host.

**Fix verified — PASS (3/3 sites):**
- `includes/class-google-fonts.php:112`: `wp_parse_url($href, PHP_URL_HOST) !== 'fonts.googleapis.com'` (exact match) for `style_loader_tag` path.
- `includes/class-google-fonts.php:271-278`: `normalize_google_fonts_url()` now parses host, rejects unless `=== 'fonts.googleapis.com'` (and rejects `fonts.gstatic.com` — CSS must be googleapis; gstatic is font files only), returning `''` on mismatch.
- `includes/class-google-fonts.php:302`: `download_font_file()` adds `wp_parse_url($url, PHP_URL_HOST) !== 'fonts.gstatic.com'` guard — only gstatic font files fetched.
- `process_buffer` regex still matches substring (`fonts.googleapis.com` anywhere), but the extracted URL is immediately passed through `normalize_google_fonts_url` → exact-host check, so no `wp_remote_get` to evil host. Verified `download_and_rewrite:177` calls `normalize_google_fonts_url` first.
- **Edge case:** relative/protocol-relative URLs (`//fonts.googleapis.com/css`) have host correctly parsed by `wp_parse_url` (returns `fonts.googleapis.com`), still passes. URLs without host (e.g. `/fonts.googleapis.com.evil`) return `null` → rejected. Empty/invalid URL → rejected. Good.
- **New vuln check:** none; host allowlist is stricter (no subdomain wildcard). `fonts.googleapis.com.evil.com` host = `fonts.googleapis.com.evil.com` ≠ allowlist → rejected.

### 2.3 U02 / A-FILE-01 — Uninstall symlink traversal (MEDIUM)

**Original:** `uninstall.php:109-134` `wppo_delete_directory()` used `scandir` → `is_dir($path) ? recurse : unlink` — `is_dir` follows symlinks. Planted symlink inside `wp-content/cache/wppo` or `wp-content/wppo` caused arbitrary directory deletion on uninstall.

**Fix verified — PASS:**
- `uninstall.php:115-120`: top-of-function guard:
  ```php
  if ( is_link( $dir ) ) { @unlink( $dir ); return; }
  ```
  prevents following a symlink passed as root (`$cache_dir` or `$wppo_dir` themselves symlinked).
- `uninstall.php:141-144`: inside loop, before `is_dir`:
  ```php
  if ( is_link( $path ) ) { @unlink( $path ); continue; }
  ```
  deletes link only, never recurses. Comment notes `is_dir follows symlinks, check must be first`.
- **Order correct:** `is_link` before `is_dir` is mandatory; implementation respects it at both levels.
- **Functionality preserved:** Regular files/dirs still deleted; symlinked files inside cache now removed as links (not targets). No regression for normal installs (no symlinks expected). `@` silencing + PHPCS ignores retained.

### 2.4 RUM — Token scope, rate-limit, bounded storage, injection of fixes (HIGH/contextual)

**Original:** Single public endpoint `rum_collect` (`__return_true`) with `wp_hash('wppo_rum_'.Ymd)` token not scoped to path, no IP rate-limit test, per-beacon `get_option+update_option` write hotspot, unbounded option growth concerns.

**Fixes verified — PASS (with caveats noted in §4):**

| Aspect | File:Line | Evidence | Verdict |
|---|---|---|---|
| **Token per-path + daily rotation** | `includes/class-rum.php:219-221`, `232-242` | `token_for(int $ts, string $path)` → `wp_hash('wppo_rum_'.gmdate('Ymd',$ts).'|'.$path)`; `is_valid_token` checks today + yesterday via `hash_equals` loop | PASS — leaked token only valid for its own path + 24h window; `hash_equals` prevents timing leak. `print_config:195-199` mints per `REQUEST_URI` path. |
| **Rate limit per-IP, multisite-safe** | `includes/class-rum.php:251-259` | `$key = Util::transient_key('wppo_rum_ratelimit_'.md5($ip)); $count=(int)get_transient($key); if($count>=120) return true; set_transient($key,$count+1,HOUR_IN_SECONDS)` | PASS with race caveat (§4). `md5($ip)` avoids transient key illegal chars; `Util::transient_key` prefixes with blog_id on multisite. |
| **REST route justification** | `includes/class-rest.php:215-229`, `259-271` | Docblock documents 3 compensating controls (daily rolling token, 120/h per-IP, 14d×200 paths/day bounded eviction); `permission_callback => __return_true` explicitly marked intentional + reviewed (A08 A-AUTH-01) | PASS — `__return_true` no longer undocumented. |
| **Bounded storage** | `includes/class-rum.php:44-45`, `410-415`, `419-424` | `MAX_DAYS=14`, `MAX_PATHS_PER_DAY=200` with `array_shift` oldest-path eviction; cutoff via `gmdate Y-m-d` + `MAX_DAYS*DAY_IN_SECONDS` | PASS — prevents option bloat/DoS; 200×14 variants bounded. |
| **Metric clamping** | `includes/class-rum.php:277-296` | `ttfb/fcp/lcp/inp` clamped `[0,60000]`, `cls [0,1]`; `is_finite` check → `null` on NaN/Inf; `path` via `wp_parse_url(...PHP_URL_PATH)` + `substr 512` | PASS — `sanitize_sample` rejects infinite/NaN, clamps extremes, normalizes path (drops query/fragment). |
| **Queue + flush lock (DoS mitigation)** | `includes/class-rum.php:317-431` | `store_sample` buffers to transient `wppo_rum_queue` (max 100, forced flush at 20, random 10% flush, cron fallback `wppo_rum_flush +300s`); `flush_queue` uses transient lock `wppo_rum_flush_lock` 30s, copy-then-delete queue before aggregation, timestamp bucketed by `_ts` (sample day, not flush day) | PASS — reduces `update_option` from 1/beacon to ~1/20; lock prevents concurrent flush duplication. `Util::transient_key` used for both. |
| **Cron wiring** | `includes/class-cron.php:74`, `409` | `add_action('wppo_rum_flush', ['RUM','flush_queue'])` + `wp_clear_scheduled_hook('wppo_rum_flush')` on unschedule | PASS |
| **get_data freshness** | `includes/class-rum.php:153-157` | `get_data()` calls `flush_queue()` opportunistically before `get_option` | PASS — dashboard sees near-real-time. |

**Read directly:** `includes/class-rum.php:100-144` `collect()` order is `is_enabled → is_valid_token → is_rate_limited → sanitize_sample → store_sample` — correct (cheap checks first, rate-limit only after token valid).

### 2.5 Database Cleanup — SQLi via `$wpdb->prepare`, table allowlist (MEDIUM + consistency)

**Original:** Repeated copy-paste `SELECT IDs LIMIT 1000 → DELETE meta → DELETE posts` across 5 `clean_*` methods; `TABLE_MAP`/`CLEANUP_METHOD_MAP` duplicated across `Database_Cleanup`, `Rest`, `Abilities`, `WPPO_CLI`; `get_table_size` via `information_schema` failed on restricted DB users; `OPTIMIZE TABLE` interpolated without explicit allowlist comment; `core_tweaks`/`litespeed_integration` drift.

**Fixes verified — PASS:**

- **Central helper:** `includes/class-database-cleanup.php:138-180` `delete_in_batches(string $select_sql, string $meta_table, string $meta_column, string $main_table, string $id_column, int $batch)` — single loop with `$wpdb->last_error` check, `get_col($select_sql)` (select SQL uses `$wpdb->posts`/`$wpdb->comments` — core table names, not user input), placeholders `implode(',', array_fill(0,count($ids),'%d'))` + `$wpdb->prepare("DELETE FROM {$meta_table} WHERE {$meta_column} IN ($placeholders)", ...$ids)` — correctly prepares values (table/column names are `$wpdb->postmeta` etc., allowlisted). Callers `clean_revisions:190`, `clean_auto_drafts:336`, `clean_trashed_posts:355`, `clean_spam_comments:372`, `clean_trashed_comments:387` all delegate with fixed table names. **No SQLi:** values parametrized, identifiers are `$wpdb->*` constants.

- **Single source of truth:** `includes/class-database-cleanup.php:81-91` `CLEANUP_METHOD_MAP` (9 types), `get_valid_cleanup_types():109-110` adds `'all'`, `get_cleanup_method_map()`. `includes/class-rest.php:805` `database_cleanup` uses `Database_Cleanup::get_valid_cleanup_types()`, `856` uses `CLEANUP_METHOD_MAP`; `705` `import_settings` uses `Util::ALLOWED_SETTINGS_KEYS`; `includes/class-abilities.php:451`, `465` use `Database_Cleanup::*`; `includes/class-wppo-cli-command.php:627`, `711` use `Util::ALLOWED_SETTINGS_KEYS/TABS`. 4-way drift eliminated.

- **`get_table_size` fallback:** `includes/class-database-cleanup.php:956-988` — after `information_schema` query, if `null/''` or `!empty($wpdb->last_error)`, falls back to `SHOW TABLE STATUS LIKE %s` with `$wpdb->prepare`, summing `Data_length+Index_length`. Handles restricted DB users. `last_error` reset before fallback.

- **`optimize_table` allowlist:** `includes/class-database-cleanup.php:990-1035` — docblock explicitly justifies direct interpolation (`$full_table_name = $wpdb->{$table}` after `TABLE_MAP` allowlist, identifiers cannot be `%s`), callers only via `maybe_optimize_tables` with allowlisted inputs (`clean_all:730`, `auto_clean:789`). 1 GB guard `get_table_size > 1073741824` skip remains. PHPCS ignore kept.

- **`auto_clean`/`clean_all` dedup:** `includes/class-database-cleanup.php:714-800` now both reference `CLEANUP_METHOD_MAP` / `TABLE_MAP` — no drift.

### 2.6 Rest `clear_cache` — `realpath` fallback for uncached pages (LOW, functionality + traversal)

**Original:** `includes/class-rest.php:371-386` validated `path` via `realpath($cache_dir.$path)` prefix check. When page not yet cached, directory doesn't exist → `realpath` returns `false` → every `clear_single_page_cache` for uncached URL wrongly returned 400 Invalid path. Also trailing-slash edge.

**Fix verified — PASS with residual encoding note (§4):**

- `includes/class-rest.php:389-415`:
  ```php
  $normalized_cache_dir_trail = trailingslashit($normalized_cache_dir);
  $candidate_path = wp_normalize_path(trailingslashit($this->cache_dir).ltrim($path,'/\\'));
  $real_path = realpath($this->cache_dir.$path);
  if (false !== $real_path) {
      // prefix check on realpath result
      if (!is_exact_match && !is_under_dir) 400;
  } else {
      // fallback: prefix check on candidate_path + explicit '..' reject
      if ((!is_exact_match && !is_under_dir) || false !== strpos($candidate_path,'..')) 400;
  }
  ```
  - `trailingslashit` used correctly for `is_under_dir` to prevent prefix collision (`/cache/wppo` matching `/cache/wppox`).
  - `candidate_path` normalized via `wp_normalize_path` + `ltrim` —— correctly handles `/`/`\` variants.
  - `..` check catches traversal when `realpath` fails. Cache deletion path `Cache::clear_cache` (`includes/class-cache.php:1989-2002`) and `get_file_path:1880` both independently re-check `strpos($url_path,'..') !== false → '' → false`, so even if Rest check were bypassed via encoding, inner layer blocks `..`.

### 2.7 Other hardening verified

| Item | File:Line | Fix | Evidence |
|---|---|---|---|
| **Util sanitize ordering** | `includes/class-util.php:799-802` | `exclude/preload/delay/list` branch now precedes `url/cdn/origin` branch | Prevents `excludeCdnUrl` misclassified as `esc_url_raw` — `sanitize_textarea_field` correctly applied. |
| **Util::ALLOWED_SETTINGS_KEYS** | `includes/class-util.php:43-64`, `includes/class-rest.php:451-452,731-732`, `includes/class-main.php:1524` | Single constant exposed to JS via `wppoSettings.allowedSettingsKeys` | `includes/class-main.php:1524` `'allowedSettingsKeys' => Util::ALLOWED_SETTINGS_KEYS` keeps PHP/JS in sync; `ALLOWED_SETTINGS_TABS = ALLOWED_SETTINGS_KEYS` alias intentional. |
| **Cache atomic writes + stampede lock** | `includes/class-cache.php:1575-1680` | `atomic_put_contents()` via tmp+`move`, transient lock `wppo_cache_write_ md5(path)` 5s, `delete_transient` in `finally` | Prevents torn `index.html` on concurrent generation; lock is per-file, multisite-safe. |
| **Advanced-cache COOKIEHASH fallback** | `includes/class-advanced-cache-handler.php:144-149` | `fallback_hash = md5(wp_parse_url($site_url, PHP_URL_HOST))` not `md5(home_url)` | Fixes scheme/path mismatch on subdirectory/http vs https installs. |
| **Cron double-fetch removal** | `includes/class-cron.php:115`, `142` | `schedule_cron_jobs` now single `Util::get_settings()` | Removes redundant `get_option` call. |
| **Critical CSS blog isolation** | `includes/class-critical-css.php:156` | `md5(get_current_blog_id().'-'.$template.'-'.get_stylesheet())` | Prevents cross-site CCSS bleed on multisite. |
| **Util settings memoization** | `includes/class-util.php:120-213` | `get_settings()` per-request memo with `update_option_wppo_settings`/`add_option`/`delete_option` hooks | Reduces deserialization hotspot; hook `ensure_settings_cache_hook` with static `$hooked` guard — correct. |
| **Used-CSS OR logic** | `includes/class-used-css.php:485` | Changed AND→OR (`matches_simple_selector` true → keep) | Fixes false-positive purging of descendant selectors `.sidebar .widget`. |
| **ImageOptimisation filesystem cache** | `includes/class-image-optimisation.php:117-128, 832-884` | `FILE_EXISTS_CACHE_LIMIT 500` FIFO + `get_cached_image_size` LRU consolidation | Prevents repeated `file_exists`/`getimagesize` stat storms. |

---

## 3. New Issues Introduced by This Patch

**No new high-severity vuln introduced.** Minor observations:

1. **Nonce preservation now sanitized (good, but check):** `includes/class-rest.php:474` `pagespeed_api_key` preservation now `sanitize_text_field`'d rather than raw stored value. Previously raw option value was re-inserted; now sanitized again — strictly safer, but if stored key contains characters that `sanitize_text_field` strips (unlikely for API keys: alphanumeric + `_`/`-`), round-trip may mutate. API keys are `sanitize_text_field` at save time anyway, so no mismatch.

2. **`core_tweaks` removed from ALLOWED_SETTINGS_KEYS:** `includes/class-util.php:43` no longer lists `'core_tweaks'`, while `includes/class-database-cleanup.php:52` `TABLE_MAP` and prior `class-wppo-cli-command.php:627` previously allowed `'core_tweaks'` in import. `Rest::import_settings` will now 400 on payloads containing `core_tweaks`. If the feature was removed intentionally (replaced by individual file_optimisation toggles + `Core_Tweaks` class), then correct; if users exported settings containing `core_tweaks`, import will break. **Not a security issue**, but a compatibility break to note. `update_settings` tabs similarly no longer accept `core_tweaks`. Check `readme.txt` — no mention.

3. **Cache stampede lock uses transient (non-atomic):** `includes/class-cache.php:1608-1621` `get_transient → set_transient` is not atomic; two concurrent writers can both see miss and both write. Lock window is 5s, so duplicate writes still possible but harmless (last writer wins, `atomic_put_contents` prevents torn file). Acceptable — transient is advisory, not mutex.

4. **RUM cron scheduling on every beacon:** `includes/class-rum.php:339-343` `if (!wp_next_scheduled('wppo_rum_flush')) wp_schedule_single_event(time()+300,...)` runs on every unsampled beacon (9/10 beacons in low-traffic case). `wp_next_scheduled` is a DB query; 10% random flush path still does this. Low traffic: harmless; high traffic: transient lock + queue threshold absorb. Could be optimized but not a security issue.

5. **`process_buffer` regex still permissive:** `includes/class-google-fonts.php:142,154` regexes look for `fonts.googleapis.com` substring anywhere inside `href`. An attacker injecting `https://evil.example.com/?x=fonts.googleapis.com` into post content would match the regex, but `download_and_rewrite` immediately rejects via exact-host check → returns original tag. So no `wp_remote_get` to evil host, but regex is overly broad. Not a vuln, but regex could be tightened to host boundary to reduce false positives.

---

## 4. Remaining Risks (not fixed / residual / pre-existing)

| ID | Severity | Detail | File:Line | Recommendation |
|---|---|---|---|---|
| **R-RUM-RACE** | LOW | `is_rate_limited:252-257` `get_transient + set_transient(count+1)` is not atomic — two concurrent beacons from same IP both read 119 → both set 120, bypass by 1 per race window. Under contended object-cache (Redis) race window larger. | `includes/class-rum.php:251` | Use atomic `wp_cache_incr` or Redis `INCR` when ext-cache present; else document eventual consistency. 120/h budget generous — abuse impact is option eviction, not privilege escalation. |
| **R-RUM-IP** | INFO | Rate-limit key uses `$_SERVER['REMOTE_ADDR']` only — behind Cloudflare/reverse proxy all users share edge IP, so legitimate users share 120/h budget; attacker rotating IP (XFF) not effective because XFF is ignored (good for spoof resistance, bad for per-user fairness). | `includes/class-rum.php:119`, `251` | Optionally honor `CF-Connecting-IP` / `HTTP_X_FORWARDED_FOR` when trusted proxy detected, or provide `wppo_rum_client_ip` filter (original audit A-SAN-01 suggestion not applied; chose strict REMOTE_ADDR). Trade-off is documented in route docblock — acceptable. |
| **R-CACHE-ENCODE** | LOW | `Rest::clear_cache` fallback `candidate_path` checks `strpos(...,'..')` literally. Path containing `%2e%2e` or `%252e` does not contain `..` → passes `candidate_path` prefix check. However `Cache::clear_cache:1992` and `get_file_path:1880` both reject `..` only literally as well, so encoded traversal still not blocked by string check, but `get_file_path` trims `/` then does `strpos(..)` → encoded payload becomes path segment `%2e%2e` which is not `..`, so it would construct `cache/wppo/domain/%2e%2e/.../index.html` — a file whose name literally contains `%2e%2e`, not traversing. `WP_Filesystem->delete` would delete that file, not escape cache root. Actual traversal requires filesystem decoding, which `wp_normalize_path` does not decode. So no directory escape. | `includes/class-rest.php:413`, `includes/class-cache.php:1880,1992` | Consider `rawurldecode` before `..` check if strict, but current behavior is safe (encoded traversal creates literal subdir, not escape). Low priority. |
| **R-DB-PREPARE-IDENT** | INFO | `delete_in_batches:159,167` interpolates `$meta_table`/`$main_table`/`$meta_column`/`$id_column` as identifiers. They're `$wpdb->postmeta` etc. (core constants), safe. `$select_sql` is also fixed per caller, not user input. No injection, but PHPCS `PreparedSQL.NotPrepared` suppressed — justified but ensure future callers only pass fixed SQL. | `includes/class-database-cleanup.php:145,159` | No change needed; keep `/* phpcs:ignore */` with note that identifiers are allowlisted constants. |
| **R-GOOGLE-BUFFER-REGEX** | INFO | `process_buffer` regexes could theoretically match evil host via substring, but host check neutralizes. No SSRF. | `includes/class-google-fonts.php:142,154` | Optional tightening: `#https?://fonts\.googleapis\.com#` anchored to `://` + host boundary. |
| **R-OPTIMIZE-LOCK** | LOW | `Database_Cleanup::optimize_table:1035` `OPTIMIZE TABLE` takes read lock; 1 GB guard + `maybe_optimize_tables` dedup mitigates long lock, but on `clean_all` with 9 types could still issue up to ~4 distinct `OPTIMIZE` sequentially. On large busy site, brief read stall. | `includes/class-database-cleanup.php:1035` | Already gated on `$size > 1GB → skip` + per-type dedup; acceptable. Consider offloading to cron if needed. |
| **R-CORE-TWEAKS-IMPORT** | LOW (compat) | Import payloads containing `core_tweaks` now rejected (400). | `includes/class-util.php:43` | If `core_tweaks` was intentionally removed, add migration that drops the key on import; else re-add it to `ALLOWED_SETTINGS_KEYS`. |
| **R-SPAM-SITE-TRANSIENT** | INFO | `clean_expired_transients` skips `_site_transient_` on multisite (queries `wp_options` only). Site transients live in `wp_sitemeta` on multisite — not cleaned. Not a security issue, but leaves stale data. | `includes/class-database-cleanup.php:431-432` | Separate job for `wp_sitemeta` if needed; out of scope for security. |

---

## 5. Complexity & Duplication

- **Complexity:** Patch reduces duplication ( `delete_in_batches` centralizes 5 methods, `CLEANUP_METHOD_MAP` single source, `should_bypass_for_litespeed` in `class-cache.php:375`, `get_cached_image_size` / `cached_file_exists` in `class-image-optimisation.php` ) but adds ~1158 lines (many are comments + guards). Net cyclomatic per method slightly improved (batch helper removes 5× ~40-line loops). `class-cache.php:save_cache_files` grew (atomic+lock try/finally) but remains linear.
- **Remaining duplication (non-security):** `is_url_excluded` string-trim vs `process_urls` duplication, `combine_css` freshness vs generation loops now reuse `eligible_handles` (triple classify → single classify) — good. No remaining security-relevant duplication.
- **No dead code introduced:** New constants (`QUEUE_KEY`, `FLUSH_LOCK_KEY`, `QUEUE_MAX`, `FLUSH_THRESHOLD`) all referenced; `ALLOWED_SETTINGS_TABS` alias correctly used.

---

## 6. Verdict

**PASS — with low residual risks.**

- **Was finding really fixed?** Yes — all 6 focus findings verified fixed via direct file reads; evidence above.
- **Functionality preserved?** Yes — admin bar still renders for admins, Google Fonts still hosts locally for allowed hosts, cache clear works for uncached pages (fallback), RUM still collects (now queued), DB cleanup still deletes same rows (via same `prepare` placeholders), uninstall still deletes cache directories (now symlink-safe). No behavioral regression detected beyond the intentional `core_tweaks` import narrowing.
- **New vulns?** None high. One low encoding edge in `clear_cache` fallback is mitigated by inner `Cache::get_file_path` `..` check and literal-path semantics. RUM race is pre-existing, not worsened.
- **Regressions?** None security-relevant. `core_tweaks` import 400 is a compatibility note, not a vuln.
- **Remaining dup?** Largely resolved for security paths; only non-security stylistic duplication remains.

**Recommendation:** Ship. Address R-RUM-RACE (atomic incr) and R-CACHE-ENCODE (`rawurldecode` before `..`) in next hardening sprint if desired; neither blocks release. Add `core_tweaks` to `ALLOWED_SETTINGS_KEYS` if export/import compatibility must be preserved, or document removal.

---

*Evidence produced by independent re-read of `git diff` + file contents; no reliance on implementation-agent self-report.*
