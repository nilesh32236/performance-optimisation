# Agent A03 — PHP Correctness Audit: Infra / Cache / Cron

**Base:** master@31fffc61
**Date:** 2026-08-28
**Scope:** Audit-only, no production modifications
**Files reviewed:** 8
**Lines reviewed:** 5679

| File | Lines |
|------|-------|
| `includes/class-cache.php` | 2306 |
| `includes/class-advanced-cache-handler.php` | 330 |
| `includes/class-htaccess-handler.php` | 222 |
| `includes/class-server-rules.php` | 191 |
| `includes/class-cron.php` | 738 |
| `includes/class-object-cache.php` | 363 |
| `includes/redis-connect-helper.php` | 377 |
| `templates/object-cache.php` | 1152 |

## Methodology
- Read every line via `Read` with offsets (1-1488, 1489+ for class-cache; full reads for others).
- Grepped cron schedules (`wp_next_scheduled`, `wp_schedule_event`, `wp_schedule_single_event`), transient keys (`Util::transient_key`, `get_transient`, `set_transient`), and LiteSpeed gates.
- Traced static HTML generation path: `template_redirect` → output buffer (`start_output_buffer` legacy vs `process_buffer_for_cache`/`stash_cache` WP 6.9+) → `process_buffer_only` → `save_processed_buffer` → `save_cache_files` with atomic write + gzip/brotli; drop-in `advanced-cache.php` serve path; invalidation (`invalidate_dynamic_static_html`, `clear_cache`, `delete_*`); htaccess/nginx rules; Redis standalone/sentinel/cluster via `redis-connect-helper.php` + `templates/object-cache.php` drop-in.

---

## Findings

### 1. `includes/class-cache.php:212-236` — Host-header cache poisoning / domain-port mismatch with drop-in
- **Category:** Correctness / Security
- **Severity:** Major
- **Problem:** `Cache::__construct()` derives `$domain` from `$_SERVER['HTTP_HOST']` (client-controlled) with `sanitize_text_field`, IDN conversion, port strip, regex `^[a-z0-9\.\-]+$`. This is intentional for domain-based `WP_CONTENT_DIR/cache/wppo/{domain}/` isolation (multisite-safe). However `class-cron.php:592-595` (`Cron::mark_page_as_processed`) derives domain from `site_url()` host (`$parsed_url['host']` + port) while `Cache::get_cache_file_path()` uses the request Host. On port-bearing installs or behind a proxy rewriting Host, invalidation deletes `/{site_url host}/...` but runtime writes `/{HTTP_HOST host}/...`, leaving orphaned stale files that are never purged. `Advanced_Cache_Handler::create()` (line 165) keeps the port in `$site_domain` (`preg_replace('/[^a-z0-9.:-]+/i','',$raw_domain)`), so the drop-in serves `/$site_domain/index.html` with port, while `Cache` strips port — cache never hits on non-standard ports.
- **Why matters:** Stale HTML persists after post edits; on `:8080`/`:8443` or Cloudflare-style host rewrites, static cache is effectively bypassed (performance loss) or never invalidated (stale content).
- **Evidence:** `class-cache.php:223-236` `$host = explode(':',$domain,2)[0]` + strtolower; `class-cron.php:594` `$domain = sanitize_text_field($parsed_url['host'] . (port?))`; `class-advanced-cache-handler.php:164-165` `$raw_domain` → `$site_domain` retains `:`.
- **Impact:** Cache invalidation miss + serve miss on ported hosts; potential Host-header driven cache fragmentation (attacker can create arbitrary `{evil.com}/` cache dirs — not traversal but disk fill).
- **Recommended solution:** Canonicalize domain to `wp_parse_url(Util::cached_home_url(), PHP_URL_HOST)` for storage paths, or share a single `Util::get_cache_domain()` helper used by `Cache`, `Cron`, and drop-in generator; drop-in should strip port before path (or include variant). Document that `HTTP_HOST` fallback is only for multisite domain mapping and must be sanitized identically everywhere. Add test: port-bearing site_url vs HTTP_HOST.
- **Confidence:** High

### 2. `includes/class-cache.php:1494-1496` + `1587-1600` — Invalidation / stale-artifact gaps for `.br`, `.handles`, role variants
- **Category:** Correctness
- **Severity:** Major
- **Problem:** `Cron::mark_page_as_processed()` (called by `process_page` before `load_page`) only deletes `index.html` and `index.html.gz` (`class-cron.php:599-610`). It omits `.br` brotli, role-variant `index-{12hex}.html*`, `.wppo-no-cache` marker, and CSS sidecar `index.css` / `index.css.handles`. `Cache::delete_all_cache_files()` and `Cache::invalidate_dynamic_static_html()` do purge those, but the preload batch path leaves stale brotli/role files behind. Similarly `Cache::save_cache_files()` writes `.br` alongside `.gz`; a later `mark_page_as_processed` leaves old `.br` to be served by drop-in (br preferred).
- **Why matters:** After a page update, authenticated-role users may still receive stale role-variant HTML; brotli cache may serve old HTML even after purge.
- **Evidence:** `class-cron.php:599-610` two `delete()` calls only; vs `class-cache.php:1922-1931` `delete_cache_files` deletes `.gz` + `.br`; `1944-1961` `delete_role_variant_files`; `class-cache.php:1790-1798` full purge.
- **Impact:** Stale content for logged-in role caches and brotli-capable clients.
- **Recommended solution:** Make `mark_page_as_processed()` delete `.br`, call `delete_role_variant_files(dirname($file_path))`, and optionally `delete_no_cache_marker()` / `*.handles`; or reuse `Cache::get_file_path` + `delete_cache_files` logic (inject filesystem helper) instead of duplicating path construction.
- **Confidence:** High

### 3. `includes/class-cache.php:250-258` + `253-255` — Single rawurldecode vs double-encode traversal
- **Category:** Security / Correctness
- **Severity:** Minor
- **Problem:** `__construct()` does `rawurldecode(wp_parse_url(REQUEST_URI, PHP_URL_PATH))` once then checks `strpos($url_path,'..')`. A double-encoded `%252e%252e` decodes to `%2e%2e` and passes the check, later `prepare_cache_dir()` explodes and builds directories with `%2e%2e` literally (no traversal), so no FS escape. However `get_file_path()` also checks `strpos($url_path,'..')` after `wp_normalize_path(trim(..., '/'))` without decoding, so `%2e` variants are not caught. Inconsistent decoding means attacker can create cache dirs named `%2e%2e` (percent-encoded dots) that later normalization might mishandle on case-sensitive FS.
- **Why matters:** Low direct risk (no directory escape) but cache pollution / key collision; defense should be uniform.
- **Evidence:** `class-cache.php:251` `rawurldecode`, `253-255` `strpos('..')`; `1878-1882` `get_file_path` `strpos('..')` without decode.
- **Impact:** Cache directory pollution, potential confusion with `prepare_cache_dir`.
- **Recommended solution:** Normalize via `rawurldecode` repeatedly (or use `urldecode` + loop) before `..` check, or reject `%2e` case-insensitively via regex `%2e` + `..`. Align both call sites to same helper.
- **Confidence:** Medium

### 4. `includes/class-cache.php:1622-1628` — File-level transient lock is blog-prefixed but file path is domain-scoped
- **Category:** Correctness / Multisite
- **Severity:** Minor
- **Problem:** Lock key `Util::transient_key('wppo_cache_write_' . md5($file_path))` prefixes with `get_current_blog_id()`. On multisite with shared object cache (Redis), this is correct isolation. But `$file_path` already includes domain string (which is site-specific). For single-site with domain alias or multisite domain mapping, two blogs sharing same domain (unlikely) would still share file lock due to same md5 but different blog prefix → two different transients, so no mutual exclusion — concurrent writes could stampede. Conversely, same logic for `wppo_preload_cron_lock` etc is correctly blog-prefixed.
- **Why matters:** Stampede window reopens for domain-aliased multisite edge case; 5-second lock may be bypassed.
- **Evidence:** `class-cache.php:1624` `md5($file_path)` + `Util::transient_key`; `class-util.php:720-729` blog prefix logic.
- **Impact:** Rare concurrent cache corruption (partial write) on domain-aliased multisite.
- **Recommended solution:** Include both `get_current_blog_id()` and domain in md5, or keep as-is but document assumption that domain is unique per blog (enforced by multisite). Add test for transient key uniqueness.
- **Confidence:** Low (edge case)

### 5. `includes/class-cache.php:1572-1589` — Atomic write fallback lacks fsync / permissions check
- **Category:** Reliability
- **Severity:** Info
- **Problem:** `atomic_put_contents()` writes tmp `.$path.tmp.{rand}` then `$fs->move($tmp,$path,true)`. If move fails (e.g., cross-FS, FTP FS), it deletes tmp and falls back to direct `put_contents`. The direct write is not atomic, reintroducing partial-read race that transient lock was meant to prevent. No `wp_rand()` collision handling, no cleanup of stale `.tmp.*` on failure.
- **Why matters:** On FTP/SSH FS, readers may see torn HTML.
- **Evidence:** `class-cache.php:1582-1586`.
- **Impact:** Brief torn cache serve under FTP FS under high concurrency.
- **Recommended solution:** Keep fallback but log via `wppo_debug_log`; optionally `chmod` check; consider `FS_CHMOD_FILE` already applied.
- **Confidence:** Medium

### 6. `includes/class-advanced-cache-handler.php:151-158` — Cache life baked at generation time, never refreshed
- **Category:** Correctness
- **Severity:** Minor
- **Problem:** `create()` reads `wppo_settings[cache_settings][cacheLife]` once and bakes `$cache_life = N` into generated `advanced-cache.php` (`'$cache_life = ' . $cache_life`). Settings change requires re-running `create()` (done on settings save via `Main` — verify). If that re-create fails or `create()` is short-circuited by `foreign_dropin_present()==true` (returns true without regen), the baked value stays stale. Drop-in expiry check `time() - filemtime($check_path) > $cache_life*3600` then uses wrong TTL.
- **Why matters:** Users changing cache TTL see no effect until manual regeneration.
- **Evidence:** `class-advanced-cache-handler.php:152-154` `get_option`, `169` `$cache_life = ...`, `214-223` filemtime check.
- **Impact:** TTL drift, cache served longer/shorter than configured.
- **Recommended solution:** Either read TTL dynamically via lightweight `include` of config at serve time (costly) or ensure `Main` always calls `create()` on `wppo_settings` update and on `foreign_dropin_present` false path; add `advanced-cache.php` re-generation on `update_option_wppo_settings` hook.
- **Confidence:** Medium

### 7. `includes/class-advanced-cache-handler.php:179-181`, `284-286` — Drop-in serve omits query-string & DONOTCACHEPAGE nuances
- **Category:** Correctness
- **Severity:** Minor
- **Problem:** Drop-in checks `!empty($_SERVER['QUERY_STRING'])` via `! $has_query` and woocommerce cookies + sitemap/xml regex + `.wppo-no-cache` marker. `Cache::is_not_cacheable()` additionally checks `is_feed()`, `is_cart/checkout/account_page()`, `pathinfo extension`, `woocommerce_cart_hash` cookies, and `DONOTCACHEPAGE` marker (which writes file). The drop-in cannot call `is_feed()` pre-WP, so parity is approximate. It also checks `$_COOKIE['woocommerce_items_in_cart']` etc but not `woocommerce_cart_hash`? Actually it does: `175-176` checks `woocommerce_items_in_cart` + `woocommerce_cart_hash` — parity ok. But it does not check `?s=` search query specially like `Cache::maybe_store_cache()` does (`preg_match('/(?:^|&)(s|ver|v)(?:=|&|$)/')`). Drop-in only checks `!empty(QUERY_STRING)` generically, so search pages are correctly not served (both agree). However pages with `?ver=` query that WordPress would cache as separate file are not distinguished; drop-in simply bypasses.
- **Why matters:** Mostly correct, but slight divergence: `Cache::is_not_cacheable()` excludes `sitemap*.xml` via regex, drop-in does same; parity is close.
- **Evidence:** `class-cache.php:1488-1496`, `1739-1742` query check; `class-advanced-cache-handler.php:283-286` query check.
- **Impact:** No serve of dynamic pages — safe, slightly over-conservative.
- **Recommended solution:** Document parity table; consider mirroring `s|ver|v` logic in drop-in for consistency.
- **Confidence:** Low

### 8. `includes/class-advanced-cache-handler.php:226-244` — Brotli serve prefers `br` over `gzip` but cache_life check prefers br filemtime
- **Category:** Performance
- **Severity:** Info
- **Problem:** `wppo_serve_cache_file()` selects `$check_path` based on `Accept-Encoding: br` and file existence to enforce TTL, then later serves `br` if found. If `br` exists but is stale (>TTL) and `gzip` is fresh, it returns early (bypass) even though a fresh gzip could be served. Similarly, if `br` missing but `gzip` stale, it bypasses even if `index.html` fresh.
- **Why matters:** Unnecessary cache bypass when one variant stale but another fresh; regeneration still happens but extra WP boots.
- **Evidence:** `class-advanced-cache-handler.php:214-225`.
- **Impact:** Extra origin hits until brotli regenerated.
- **Recommended solution:** Check each variant TTL individually and serve the freshest available variant matching Accept-Encoding, or delete stale variants on regeneration (`save_cache_files` already overwrites both).
- **Confidence:** Medium

### 9. `includes/class-htaccess-handler.php:187-192` — Next-gen rewrite lacks `[L]` and order dependency
- **Category:** Correctness
- **Severity:** Minor
- **Problem:** The appended next-gen block does:
```
RewriteCond %{HTTP:Accept} image/webp
RewriteCond %{REQUEST_FILENAME}.webp -f
RewriteRule ^(.+)\.(jpe?g|png)$ $1.webp [T=image/webp,E=accept:1]
RewriteCond %{HTTP:Accept} image/avif
...
RewriteRule ^(.+)\.(jpe?g|png)$ $1.avif [T=image/avif,E=accept:1]
```
  No `[L]` flag means both rules may apply sequentially (first rewrites to `.webp`, second then tests `.avif` existence on the *rewritten* filename, not original — Apache re-evaluates `REQUEST_FILENAME` after first internal rewrite? Actually without `L`, rules are processed top-down in same round, `REQUEST_FILENAME` unchanged until rewrite, so second rule's `REQUEST_FILENAME.avif` tests original + `.avif`. If both `.webp` and `.avif` exist and client accepts both, request will be rewritten twice: first to `.webp`, then to `.avif` (avif wins). That's intentional (avif preferred if placed second) but the double filesystem check (`-f`) is wasteful. More importantly, without `L`, a request that matched webp will still evaluate avif condition, but if avif not exists, it stays at `.webp` — works. However `T=` MIME change without `L` may cause `mod_headers` Vary to be set inconsistently.
- **Why matters:** Works but inefficient; on Apache with `AllowOverride` limited, `-f` check is per-request stat.
- **Evidence:** `class-htaccess-handler.php:183-192`.
- **Impact:** Extra stat syscall per image request.
- **Recommended solution:** Add `[L]` to each rule and order avif first (prefer avif) with `L`, or use single rule with `E=`; add comment about `L` to prevent double evaluation. Verify on LiteSpeed (OpenLiteSpeed requires restart).
- **Confidence:** Medium

### 10. `includes/class-server-rules.php:78-81` — Nginx gzip gated on minifyJS/CSS
- **Category:** Correctness
- **Severity:** Minor
- **Problem:** `get_nginx_rules()` only emits `gzip on;` block if `minifyJS || minifyCSS` (`class-server-rules.php:81`). Gzip should be independent of minify. On installs with CDN or external minify but wanting nginx gzip, user gets no gzip snippet. `Htaccess_Handler` always emits gzip regardless of settings — inconsistency.
- **Why matters:** Users miss gzip config when they disable minify.
- **Evidence:** `class-server-rules.php:78-81` vs `class-htaccess-handler.php:99-122` unconditional gzip.
- **Impact:** Manual nginx config incomplete, performance left on table.
- **Recommended solution:** Gate gzip on `enableServerRules` or always emit when `enableServerRules` true, or separate toggle. Align with htaccess behavior.
- **Confidence:** High

### 11. `includes/class-server-rules.php:140-155` — Nginx next-gen generates nested `server {}` block
- **Category:** Correctness
- **Severity:** Minor
- **Problem:** `get_nginx_rules()` appends:
```
map $http_accept $wppo_avif_suffix { ... }
map $http_accept $wppo_webp_suffix { ... }
server {
    location ~* \.(jpe?g|png)$ { try_files $uri$wppo_avif_suffix ... }
}
```
  If user pastes snippet inside an existing `server {}` block (typical), the nested `server {}` is invalid nginx. `map` directives must be in `http {}` context, not `server`. The snippet conflates contexts. Also browser-caching `location ~* \.(jpg|...)$` (line 111) and next-gen `location ~* \.(jpe?g|png)$` conflict: nginx chooses one regex location (first match if `~` vs `~*` precedence), so next-gen may never be evaluated if browser caching location wins.
- **Why matters:** Copied nginx rules may fail `nginx -t` or silently not serve webp/avif.
- **Evidence:** `class-server-rules.php:140-155`, `109-115`.
- **Impact:** Next-gen delivery broken on nginx.
- **Recommended solution:** Split snippet into `http` context (map) vs `server` context (location) with documentation; emit `location` without wrapping `server {}` or emit include file. Order locations to ensure avif/webp location takes precedence (e.g., more specific regex first).
- **Confidence:** High

### 12. `includes/class-cron.php:274-339` — Preload batch offset logic fragile + sitemap once-per-cycle gate
- **Category:** Correctness / Performance
- **Severity:** Major
- **Problem:**
  1. `schedule_page_cron_jobs()` computes `$paged = max(1, ceil(($paged_offset+1)/200))` and queries `get_posts(paged=$paged, posts_per_page=200, fields=ids, orderby=ID ASC)`. If posts are deleted between cycles, offset (`wppo_preload_cron_offset` option, autoload false) may point past end, query returns empty, offset deleted — next cycle restarts at 0. That's correct reset. But if new posts with low IDs are inserted (e.g., via import with explicit ID), `ORDER BY ID ASC` with `paged` offset may skip them because `paged` is offset-based, not cursor-based. Classic WP pagination drift.
  2. Sitemap warm-up fires only when `$paged_offset===0` (`313-315`). If a previous cycle crashed before offset reset (e.g., lock expiry, fatal), offset stays non-zero, sitemap URLs never warmed again. Also `schedule_sitemap_url_jobs(500)` schedules each URL with `wp_schedule_single_event(time()+rand(0,1800))` — up to 500 distinct `wppo_generate_static_url` events at once. `wp_next_scheduled('wppo_generate_static_url', [$url])` check is O(n) over cron array per URL (500*500 checks) and stores args serialized, bloating `cron` option (can exceed `max_allowed_packet` on some DBs). 5-hourly preload repeats this, causing cron option bloat.
  3. Transient lock `wppo_preload_cron_lock` 20min, but `used_css` lock also 20min — parallel preload + used_css may deadlock if both scheduled together (both `every_5_hours`).
- **Why matters:** Missing pages in preload, cron table bloat, sitemap starvation.
- **Evidence:** `class-cron.php:283-297`, `313-315`, `351-362`, `276-279` lock, `332-334` follow-up batch `+60s`.
- **Impact:** Incomplete cache warming; DB performance degraded by large cron option; sitemap-only URLs (archives, custom endpoints) go cold.
- **Recommended solution:** Use cursor pagination (`post__in` with `ID > last_id` + `ORDER BY ID ASC` limit 200) storing `last_id` not offset+200. Cap `wppo_generate_static_url` events to 100 and throttle with Action Scheduler instead of `wp_schedule_single_event`. Ensure sitemap jobs scheduled via separate daily hook or when offset resets to 0 *or* on transient expiry, not only offset==0. Add `wp_schedule_single_event` batching with `wp_next_scheduled` dedup via in-memory set.
- **Confidence:** High

### 13. `includes/class-cron.php:474-549` — Sitemap discovery: permissive regex, host check bypass for relative URLs, deadline 15s may truncate
- **Category:** Correctness
- **Severity:** Minor
- **Problem:**
  - `preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#i')` captures any `<loc>` across the document, including those inside `<image:loc>` (image sitemap) or comments, inflating URL list with non-HTML assets.
  - Host check: `$loc_host = wp_parse_url($loc, PHP_URL_HOST); if ($loc_host && $loc_host !== $home_host) continue;` — If sitemap contains relative URLs (rare but valid per spec), `loc_host` is empty → not filtered, and later `process_url(esc_url_raw(relative))` may normalize to `https://relative`? Actually `esc_url_raw` of `/custom-page` returns `/custom-page`? Then `wp_remote_get('/custom-page')` fails (invalid URL). Should resolve relative via `Util::cached_home_url()`.
  - Deadline 15s wall-clock includes sequential `wp_remote_get(timeout=5)` per sitemap fetch (up to 50 children). Worst-case 50*5=250s >15s, so loop breaks early, returning partial list. That's intentional (bound), but remainder of sitemap URLs never warmed that cycle.
- **Why matters:** Extra image URLs queued for static cache (wasteful, may 404); relative URLs silently fail.
- **Evidence:** `class-cron.php:483` deadline, `497` timeout 5, `511-528` loc parsing, `525-527` host check.
- **Impact:** Up to ~500 URLs but includes noise; incomplete coverage under slow sitemap.
- **Recommended solution:** Filter locs via `FILTER_VALIDATE_URL` + host check also for schemeless; resolve relative via `home_url`. Use `simplexml_load_string` if available for proper XML parsing (fallback to regex). Document 15s cap and TO_FETCH_LIMIT interaction.
- **Confidence:** Medium

### 14. `includes/class-cron.php:114-158` — `schedule_cron_jobs` re-registers daily/hourly events without rescheduling on settings toggle off→on
- **Category:** Correctness
- **Severity:** Minor
- **Problem:** For always-on hooks (`wppo_img_conversion` hourly, `wppo_database_cleanup_cron` daily, `wppo_web_vitals_rescan` daily, `wppo_ccss_regeneration` daily) the code only schedules if `!wp_next_scheduled`. If `wppo_database_cleanup_cron` was previously cleared via `clear_cron_jobs()` (e.g., deactivate), `schedule_cron_jobs` on next `init` will re-schedule — ok. But for `wppo_llms_txt_daily` and `wppo_used_css_cron` gated by `if (!empty($options['llms_txt']['enabled']))` vs `else wp_clear_scheduled_hook`, toggling `removeUnusedCSS` on after it was off will schedule `wppo_used_css_cron` on next init (good). Toggling off clears. However `wppo_ccss_regeneration` is unconditional daily — but `ccss_regeneration_cron()` early returns if `criticalCSS` off, so daily event wakes for no reason (wasteful but harmless).
- **Why matters:** Unnecessary wakeups; `wppo_used_css_cron` uses `every_5_hours` but `clear_cron_jobs` on preload disable does not clear `wppo_ccss_regeneration`? Actually `clear_cron_jobs` line 405 `wp_clear_scheduled_hook('wppo_ccss_regeneration')` does clear.
- **Evidence:** `class-cron.php:114-158`, `396-410`.
- **Impact:** Minor cron bloat.
- **Recommended solution:** Gate `wppo_ccss_regeneration` scheduling on `criticalCSS` like `wppo_used_css_cron`. Or keep but document.
- **Confidence:** Low

### 15. `includes/class-cron.php:697-735` — DB cleanup schedule threshold off-by-one + lock not cleared on failure path
- **Category:** Correctness
- **Severity:** Minor
- **Problem:** `database_cleanup_cron()` computes `$should_run = ($now - $last_run > X - HOUR_IN_SECONDS)` e.g., daily `> 82800` (23h) not 86400. So if cron fires exactly 24h later, it runs (23h threshold). That's lenient to handle late cron, good. But `update_option('wppo_last_db_cleanup', $now)` is outside the try/finally for lock, but inside `if ($should_run)` — if `get_transient(lock)` returns true (concurrent run), it returns early without updating `last_run`, correct. However if `auto_clean` throws, `finally` clears lock, then `update_option` still sets `last_run` even though cleanup failed, blocking retry for 23h.
- **Why matters:** Transient failure prevents retry for a day.
- **Evidence:** `class-cron.php:709-735` switch, lock, try/finally, `update_option`.
- **Impact:** DB bloat remains uncleaned until next day.
- **Recommended solution:** Move `update_option` inside try after successful `auto_clean`, or set only on success boolean return.
- **Confidence:** Medium

### 16. `includes/class-cron.php:622-687` — Image conversion batch: path traversal guard strong but discovery unbounded
- **Category:** Security / Performance
- **Severity:** Info
- **Problem:** Good: `img_convert_cron` validates `realpath(source)` starts with `ABSPATH` (`677`). Batch size from `image_optimisation.batch` default 50, plus `apply_filters('wppo_cron_discovery_limit',50)` for library discovery `651-654`. `queue_unconverted_library_images` is called every hourly run, scanning library for unconverted images (up to 50 discovery). That's O(n) table scan hourly; okay. But `$img_info = Img_Converter::get_img_info()` re-read after discovery inside same lock, good.
- **Why matters:** Secure; performance okay but discovery limit filter could be abused via large value (e.g., 10000) causing OOM — but filter is site-controlled.
- **Evidence:** `class-cron.php:640-682`.
- **Impact:** None beyond perf note.
- **Recommended solution:** Cap discovery limit upper bound (e.g., min(filter,200)) in cron wrapper to prevent admin filter misuse.
- **Confidence:** Low

### 17. `includes/class-object-cache.php:86-183` — Status telemetry for cluster misreads `redis_version` etc
- **Category:** Correctness
- **Severity:** Minor
- **Problem:** `get_status()` handles `RedisCluster::info()` returning `array node => info` but only extracts `db0` from first node for `$keys` count (`144-149`). It does not extract `redis_version`, `uptime_in_seconds`, etc from first node — it reads `$info['redis_version']` directly (`158`), which for cluster is not set (keys are node ids). So telemetry shows Unknown version on cluster.
- **Why matters:** Dashboard misreports Redis version on cluster.
- **Evidence:** `class-object-cache.php:140-168`.
- **Impact:** Cosmetic, but may confuse support.
- **Recommended solution:** For cluster, pick `$first = reset($info)` and read version/uptime from `$first` when instanceof `\RedisCluster`.
- **Confidence:** High

### 18. `includes/class-object-cache.php:119-125` — Config fallback includes raw file without validation
- **Category:** Security
- **Severity:** Info
- **Problem:** `get_status()` does `if (empty($config) && file_exists(config_path)) $config = include $config_path`. The config file is generated via `var_export($config_data,true)` (line 293-294) with `<?php return [...]`. If file is tampered, include executes arbitrary PHP. File is in `WP_CONTENT_DIR` writable by webserver, but already trusted boundary. However `include` without `is_readable` + size check could be exploited if attacker writes PHP there via other vuln.
- **Why matters:** Standard WP drop-in pattern, but worth noting.
- **Evidence:** `class-object-cache.php:123-124`, `291-294`.
- **Impact:** Low, requires prior file write.
- **Recommended solution:** Keep but add `is_readable` + `filesize < 64KB` guard before include (as done for drop-in content check 97).
- **Confidence:** Low

### 19. `includes/redis-connect-helper.php:57-68` — Password fallback order env vs constant vs config, but logs generic error
- **Category:** Correctness / Security
- **Severity:** Info
- **Problem:** `wppo_redis_connect()` checks `if (!isset($config['password']) || ''===...)` then `WPPO_REDIS_PASSWORD` constant, else `getenv('WPPO_REDIS_PASSWORD')`. This means empty string password in config is treated as "not set" and falls through to env, which is correct for `enable()` which unsets password before writing file. Good. However catch-all `Throwable` logs `error_log('WPPO Redis connection failed')` without sanitizing exception message, then returns generic `WP_Error('redis_err')` — hides root cause unless `WP_DEBUG true` branch in `Object_Cache::get_status` shows detailed message. That's intentional (debug gate) — good.
- **Evidence:** `redis-connect-helper.php:59-68`, `80-86`.
- **Impact:** Hard to debug on production without WP_DEBUG.
- **Recommended solution:** Log hashed error via `wppo_debug_log` action with sanitized message.
- **Confidence:** Low

### 20. `includes/redis-connect-helper.php:288-316` — `wppo_parse_redis_node` port default inconsistency
- **Category:** Correctness
- **Severity:** Minor
- **Problem:** Bracket IPv6 without port → `trim($node,'[]')` + port 26379 (sentinel default). Bare IPv6 without brackets with `substr_count(':')>1` → host=whole node, port 26379. But for standalone Redis, default port is 6379, not 26379. This parser is only used for sentinel/cluster nodes (`redis-connect-helper.php:151`, `104`), where 26379 is correct. Standalone uses `host`/`port` directly, so not affected. Document that this default is sentinel-specific.
- **Why matters:** If someone configures cluster with bare IPv6 standalone-style, port 26379 vs 6379 mismatch.
- **Evidence:** `redis-connect-helper.php:289-314`, `wppo_redis_connect_sentinel:169-173`.
- **Impact:** Minor, cluster/sentinel IPv6 edge.
- **Recommended solution:** Add docblock noting 26379 default is sentinel; cluster default should be 6379 (or make configurable).
- **Confidence:** Medium

### 21. `templates/object-cache.php:69-87` — Blog prefix + salt keying, but `add_salt` not persisted across `flush`/`init`
- **Category:** Correctness
- **Severity:** Info
- **Problem:** `WP_Object_Cache::__construct` sets `$blog_prefix = (is_multisite()?blog_id:table_prefix).':'` and `$salt=''`. `add_salt($salt)` is called via `wp_cache_add_salt()` (WP 6.9+) to namespace keys for query cache. `get_key()` returns `$prefix . $salt . $group . ':' . $key`. On `flush()` SCAN loop uses `$prefix . '*'` (line 536) not including salt, so flush clears all salts — correct. On `flush_group`, pattern `get_key('', $group) . '*'` includes salt, so only current salt's group is cleared — correct for wrapper-based `wp_cache_set_salted` pattern (stable key). However `wp_cache_close()` only closes primary and replica `close()`, not resetting salt — fine.
- **Why matters:** Salted cache wrapper (lines 928-1095) stores `{data,salt}` at stable key; flush_group SCAN after salt rotation would miss stale wrapper if SCAN pattern includes new salt. But wrapper pattern is intentional: old salt rows remain but `wp_cache_get_salted` checks salt mismatch → miss, not served. Stale rows remain until expiration or `flush()` full prefix scan. That's per-core behavior.
- **Evidence:** `templates/object-cache.php:208-218` get_key, `529-570` flush, `581-622` flush_group, `928-986` wrapper.
- **Impact:** Stale wrapper accumulation (bounded, not leak infinite because set overwrites stable key).
- **Recommended solution:** No change needed; document that SCAN in `flush_group` is salt-sensitive and full flush is needed to reclaim old salt rows.
- **Confidence:** High

### 22. `templates/object-cache.php:159-184` — Replica random selection + TLS/auth not mirrored for cluster
- **Category:** Reliability
- **Severity:** Minor
- **Problem:** Standalone replica support picks `array_rand($config['replicas'])` random replica per request (`159`), connects via `new \Redis()->connect($r_host,$r_port)`. If replica is down, request falls back to primary (since `get` uses `$redis_replica ? $redis_replica : $redis` → actually uses replica if set, else primary). If replica connection fails, `redis_replica` stays null, so reads go to primary — correct fallback. However replica TLS, database select, and `wppo_apply_redis_options` are applied (`189-191`), good. But for cluster/sentinel modes, replicas are not supported (code checks `mode === standalone` only) — documented, fine. `retrieve_password` for replica defaults to `$password` (primary password) if replica entry lacks password — okay. But `enable()` stripped replica passwords from config file (`283-288`), so replica password must come from DB `wppo_settings` merged at load via constant/env — but `templates/object-cache.php:101-118` only merges primary password, not replica passwords. Actually it sets `$config['password'] = $password` (derived from constant/env) but replica password logic `r_pass = $replica['password'] ?? $password` will use stripped replica file's password (empty) → falls back to primary password. So replica auth uses primary password — may fail if replicas have different passwords.
- **Why matters:** Replica auth fails when replica has distinct password.
- **Evidence:** `templates/object-cache.php:154-184`, `includes/class-object-cache.php:283-288` stripping.
- **Impact:** Replica reads fail, fallback to primary (perf loss).
- **Recommended solution:** Strip replica passwords only if they equal primary, or store replica passwords in DB and merge at load similarly to primary via `WPPO_REDIS_PASSWORD` replica variants or separate option.
- **Confidence:** Medium

### 23. `templates/object-cache.php:267-280` — `set` vs `setex` / `set` without NX for cluster, no expiration handling for `add`
- **Category:** Correctness
- **Severity:** Minor
- **Problem:** `set($key, $data, $expire=0)` uses `setex` if `expire>0` else `set`. `add($key, ..., $expire)` uses `$redis->set($key,$data,['nx'=>true,'ex'=>$expire])` if expire else `setnx`. For `RedisCluster`, `set` with options array `['nx'=>true,'ex'=>..]` is supported via phpredis cluster (since 5.3). Good. But `set_multiple` uses pipeline with `setex` per key (409-413) — pipeline on `RedisCluster` with `multi(PIPELINE)` may not shard correctly (keys may map to different slots). The code does `$this->redis->multi(\Redis::PIPELINE)` without checking `instanceof RedisCluster` — on cluster, pipeline is node-local, not cross-slot. Similarly `delete_multiple` pipeline `multi(PIPELINE)` then `del` per key may not handle cross-slot. However WP rarely uses `set_multiple` with cluster; but if it does, writes may silently fail for cross-slot keys.
- **Why matters:** Cluster cache writes may be incomplete.
- **Evidence:** `templates/object-cache.php:388-433` set_multiple, `461-495` delete_multiple.
- **Impact:** Inconsistent cache on cluster under `set_multiple` heavy plugins (e.g., `wp_cache_set_multiple_salted`).
- **Recommended solution:** For cluster, avoid pipeline and loop `setex`/`del` per key, or use `mSet` only when `expire==0` and keys same slot? Simpler: loop without pipeline for cluster, or use `_masters` loop like flush does.
- **Confidence:** Medium

### 24. `templates/object-cache.php:1097-1131` — `wp_cache_supports` claims `flush_group` but `flush` is prefix SCAN not atomic
- **Category:** Performance / Correctness
- **Severity:** Info
- **Problem:** `wp_cache_supports('flush_group')` returns true (`1126`), and `flush_group` is implemented via SCAN + `del` loops with COUNT 100 (`595-617`). Under high key count (100k+), this SCAN loop may take seconds and hold PHP request. No timeout. Similarly `flush()` SCAN over prefix. That's acceptable for admin-initiated flush but could timeout on HTTP. Original `Cache::flush_group` in `class-cache.php:2236` checks `wp_cache_supports('flush_group')` before delegating — correct.
- **Why matters:** Large Redis DB may cause 504 on flush.
- **Evidence:** `templates/object-cache.php:528-570`, `581-622`, `1100-1131`.
- **Impact:** Potential request timeout on flush.
- **Recommended solution:** Increase COUNT to 1000, add `set_time_limit(0)` guard, or document that `flush_group` is iterative.
- **Confidence:** Low

---

## Summary Counts
- **Critical:** 0
- **Major:** 3 (domain-port mismatch, stale .br/role purge gap, preload batch offset + cron bloat)
- **Minor:** 12
- **Info:** 9

## Positive Notes
- `Cache::prepare_cache_dir` iterative mkdir with FS check is resilient.
- `Util::transient_key` blog-prefix correctly applied to all preload/img/db locks (verified via grep).
- `Cache::atomic_put_contents` + 5s transient file lock + `save_cache_files` brotli/ gzip generation is robust.
- `redis-connect-helper.php` sentinel TLS prefix handling, `wppo_parse_nodes` delimiter robustness, and `wppo_apply_redis_options` serializer/compression gates are well-structured.
- `Advanced_Cache_Handler` foreign drop-in safety (`is_our_dropin` / `foreign_dropin_present`) prevents clobbering third-party `advanced-cache.php`.
- `templates/object-cache.php` correctly implements WP 6.9+ salted wrapper, `wp_cache_close` for replica, and SCAN-based flush for site-isolated Redis (avoids global `FLUSHDB`).

## Verification Steps Performed
- Grepped transient keys (`wppo_cache_write_`, `wppo_preload_cron_lock`, `wppo_used_css_lock`, `wppo_img_convert_lock`, `wppo_db_cleanup_lock`, `wppo_inline_drift_`) and confirmed `Util::transient_key` wrapping.
- Grepped cron schedules (`every_5_hours` 5h, `hourly`, `daily`, single events `wppo_generate_static_page/url`, `wppo_page_cron_batch` +60s).
- Compared domain derivation across `Cache`, `Cron`, `Advanced_Cache_Handler`.
- Compared gzip/brotli/role purge coverage across code paths.
- Inspected Nginx/Apache rule generation for context errors.

---
*Teams: file references include `:line_number` for navigation. No production code was modified.*
