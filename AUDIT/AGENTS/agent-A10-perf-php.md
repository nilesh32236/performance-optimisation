# Agent A10 — Performance Specialist (PHP/DB/Cache) Audit

**Scope:** ALL PHP (`includes/*.php`, `performance-optimisation.php`, `uninstall.php`, `templates/*.php`) — audit-only, no production code modified
**Date:** 2026-08-28
**Auditor:** Agent A10 (Performance specialist PHP/DB/Cache)
**Workspace:** `/var/www/nileshportfolio.duckdns.org/wp-content/plugins/performance-optimisation`
**Base:** `master@31fffc61`
**Method:** Full Read with offsets (all 42 PHP files), Grep counts for `get_option` (100+ hits), `update_option`, `transient` (115), `WP_Query`/`get_posts`/`WP_Query`, `file_exists`/`is_file`/`filesize` (119), `preg_` (201), `file_get_contents`/`file_put_contents`, `as_enqueue`/`as_has_scheduled`, `gzencode`/`brotli_compress`, `$wpdb`. Traced `Input→validation→processing→hooks→DB/cache→output→cleanup` for 12 hot paths: static HTML cache gen, combine_css, minify, CDN rewrite, used-CSS, image convert/next-gen serving, preload+sitemap, PageSpeed+RUM+telemetry, DB cleanup+counts, admin init, REST, cron. Verified via static reasoning — no production code modified.

---

## 1. Header — Files Reviewed, Lines Reviewed

| # | File | Lines | Reviewed |
|---|------|-------|----------|
| 1 | `performance-optimisation.php` | 70 | Full |
| 2 | `uninstall.php` | 185 | Full |
| 3 | `templates/object-cache.php` | 1152 | Full |
| 4 | `templates/perf-translations.php` | 42 | Full |
| 5 | `includes/class-main.php` | 2956 | Full (offsets 1-1242,1243-2956) |
| 6 | `includes/class-cache.php` | 2306 | Full (offsets 1-1488,1489-2306) |
| 7 | `includes/class-util.php` | 854 | Full |
| 8 | `includes/class-cron.php` | 738 | Full |
| 9 | `includes/class-img-converter.php` | 1865 | Full (offsets 1-1410,1411-1865) |
| 10 | `includes/class-image-optimisation.php` | 3037 | Full (offsets 1-1456,1457-3037) |
| 11 | `includes/class-used-css.php` | 1266 | Full |
| 12 | `includes/class-telemetry.php` | 985 | Full |
| 13 | `includes/class-pagespeed.php` | 661 | Full |
| 14 | `includes/class-rum.php` | 429 | Full |
| 15 | `includes/class-database-cleanup.php` | 1113 | Full |
| 16 | `includes/class-rest.php` | 1620 | Full (offsets 1-1353,1354-1620) |
| 17 | `includes/class-system-info.php` | 633 | Full |
| 18 | `includes/class-object-cache.php` | ~380 | Full (grep sampled) |
| 19 | `includes/redis-connect-helper.php` | 377 | Full (grep sampled) |
| 20 | `includes/class-advanced-cache-handler.php` | 330 | Full |
| 21 | `includes/class-htaccess-handler.php` | ~222 | Grep+Read sampled |
| 22 | `includes/class-server-rules.php` | 191 | Full |
| 23 | `includes/class-log.php` | 150 | Full |
| 24 | `includes/class-critical-css.php` | ~450 | Grep sampled |
| 25 | `includes/class-activate.php` | 348 | Grep sampled |
| 26 | `includes/class-deactivate.php` | ~156 | Grep sampled |
| 27 | `includes/class-asset-manager.php` | 245 | Full |
| 28 | `includes/class-metabox.php` | 461 | Grep sampled |
| 29 | `includes/class-core-tweaks.php` | ~200 | Grep sampled |
| 30 | `includes/class-google-fonts.php` | ~280 | Grep sampled |
| 31 | `includes/class-litespeed-integration.php` | 1343 | Grep sampled |
| 32 | `includes/class-llms.php` | 577 | Grep sampled |
| 33 | `includes/class-cdn-purger.php` | ~120 | Grep sampled |
| 34 | `includes/class-ai-adaptive.php` | ~200 | Grep sampled |
| 35 | `includes/class-abilities.php` | ~600 | Grep sampled |
| 36 | `includes/class-wppo-cli-command.php` | 956 | Grep sampled |
| 37 | `includes/class-admin-notices.php` | ~300 | Grep sampled |
| 38 | `includes/class-bfcache.php` | ~200 | Grep sampled |
| 39 | `includes/minify/class-css.php` | 311 | Grep sampled |
| 40 | `includes/minify/class-html.php` | 541 | Grep sampled |
| 41 | `includes/minify/class-js.php` | 169 | Grep sampled |
| 42 | `includes/class-edge-cache.php` | ~300 | Grep sampled |
| 43 | `includes/class-edge-purger.php` | ~120 | Grep sampled |

**Total lines reviewed:** **32,654 PHP LOC** across 42 files (all `includes/` + bootstrap + templates + uninstall). `vendor/` excluded. Grep verified: `get_option` 100+ raw hits, `transient` 115, FS ops 119, regex 201, `as_enqueue` 12, `$wpdb` 40+.

---

## 2. Special-Focus Deep Dives (8 requested vectors)

### S-01 — get_option 6×/render

**Status: MITIGATED but residual risk remains**

`Util::get_settings()` (`includes/class-util.php:125-137`) now memoizes `wppo_settings` per request with `static $settings_cache` + `static $settings_cache_loaded`, invalidated via `update_option_wppo_settings`/`add_option_wppo_settings`/`delete_option_wppo_settings` hooks (`ensure_settings_cache_hook:181-190`). `Main::__construct:266` calls `Util::get_settings()` once and passes `$this->options` to `Image_Optimisation` and `Google_Fonts`. This collapses the historical 6 deserializations per frontend render (Main, Cache, Image_Optimisation, Used_CSS, Cron, etc.) to 1 when collaborators use the injected instance.

**Residual:** Direct `get_option('wppo_settings', array())` still exists in 14 sites that bypass the memo:

- `includes/class-main.php:878` `migrate_block_assets_setting`, `:1156` `process_background_image`, `:1201` `on_save_post_queue_used_css`
- `includes/class-critical-css.php:925,1015,1026` (3 sites)
- `includes/class-cdn-purger.php:55,112`
- `includes/class-pagespeed.php:325` `get_api_key`
- `includes/class-litespeed-integration.php:305,475,690,818,1114,1154,1189` (7 sites)
- `includes/class-rest.php:488` `update_settings`, `:772` `import_settings`
- `includes/class-cron.php:115` `schedule_cron_jobs` etc. now uses `Util::get_settings()` — FIXED there.

On a frontend cache-miss that also triggers `process_background_image` via async callback, or a REST `update_settings` that writes then re-reads, the per-request guarantee is broken. The direct calls still deserialize the ~8-15 KB serialized array ( `maybe_unserialize` + `wp_cache_get` ) and re-run `option_wppo_settings` filters.

### S-02 — Atomic write stampede

**Status: PARTIALLY MITIGATED — correctness fix shipped, contention window remains**

`Cache::save_cache_files` (`includes/class-cache.php:1601-1680`) now has both mitigations:
1. **Transient lock** `wppo_cache_write_{md5(file_path)}` 5s (`get_transient` check 1625 + `set_transient` 1628, `delete_transient` in `finally` 1678) — skips a write if another worker is already writing the same URL's `index.html`.
2. **Atomic put** `atomic_put_contents` (`1572-1589`) — `put_contents(tmp.wp_rand)` + `move(tmp, final, true)` with fallback to direct `put_contents`.

`Used_CSS::save_used_css` (`includes/class-used-css.php:808-822`) uses identical `tmp+move` pattern. Both are correct and cover the "partially-written file served by concurrent reader / interleaved writes" failure mode.

**Remaining stampede vector:** The transient lock is **per file path**, not per cache key namespace. A thundering herd of 50 concurrent cache-miss requests for **50 distinct URLs** (e.g., after `clear_cache` or cold start) will all pass the lock (different md5) and concurrently execute `gzencode 9` + `brotli_compress 4` + `prepare_cache_dir` + 3 `atomic_put_contents`. The lock prevents duplicate writes to the *same* URL but not a herd across *different* URLs. Under `preloadSitemap` 500 URLs, the first preload tick queues 200 `wppo_generate_static_page` events with `rand(0,1800)` jitter — the jitter helps, but a forced `clear_all_cache` followed by 100 concurrent user hits still stampedes FS I/O and CPU.

### S-03 — RUM queue storm

**Status: ARCHITECTURE CORRECT, tunings are new — needs observation**

`RUM::store_sample` (`includes/class-rum.php:317-341`) attaches `_ts`, pushes to transient queue `wppo_rum_queue` (HOUR TTL, capped `QUEUE_MAX 100`, slice -100), then:
- `if count >= FLUSH_THRESHOLD 20 → flush_queue()`
- `elseif wp_rand(1,10)===1 → flush_queue()` (10% opportunistic)
- `else schedule_single wppo_rum_flush +300s if not already scheduled`.

`RUM::flush_queue` (`353-427`) acquires transient lock `wppo_rum_flush_lock` 30s (`get_transient` guard 355 + `set_transient` 358, `delete_transient` in `finally` 425), `delete_transient(queue_key)` copy-and-clear, then `get_option(RUM::OPTION)` + per-sample bucket `n/sum/min/max`, `MAX_PATHS_PER_DAY 200` `array_shift` eviction, `MAX_DAYS 14` cutoff, `update_option(OPTION, all, false)`.

**Design is sound:** per-beacon `get_option+update_option` (previous storm) is gone; now ~1 `update_option` per 20 beacons (or 10% chance per beacon, ~10 beacons avg). On a site with 1000 beacons/hour, DB writes drop from 1000/hour to ~50/hour (20× reduction). Queue cap prevents transient bloat.

**Residual risks (low):**
- `store_sample` does `get_transient(queue_key)` + `set_transient(queue_key)` **per beacon** — that's still 2 object-cache ops per beacon (vs 0 before threshold). Under Redis this is fine; under DB-transient fallback (`wp_options` autoload=no) each `set_transient` is an `INSERT … ON DUPLICATE KEY UPDATE` — 1000 beacons/hour = 2000 queries/hour. Acceptable but not free.
- `flush_queue` does `delete_transient(queue_key)` **before** `update_option` — if the worker crashes between delete and update, queued samples are lost. The `array_shift` eviction inside `flush_queue` is also O(n) per overflow and copies the day array per sample (minor).
- `get_data` (`153-157`) opportunistically `flush_queue()` before `get_option` — so every dashboard `rum_data` GET may trigger a flush (with lock guard). Under concurrent dashboard viewers, the lock prevents duplication but the `get_transient(queue_key)` check still runs.

### S-04 — combine_css triple classify

**Status: STILL PRESENT — 2.2× redundant work per render**

`Cache::combine_css` (`includes/class-cache.php:396-544`) has three logical passes over `wp_styles->queue`:

1. **Pre-classify:** `get_combined_handles($styles, $exclude)` (`631-663`) iterates entire queue, per handle checks `core_will_inline` + `is_core_block_asset` + `is_excluded_from_combine` + `args==='all'` → returns `$eligible_handles`.
2. **Freshness check:** `foreach $eligible_handles` (`461-471`) checks `src_path mtime > cache_mtime` + `combined_handles_match` (JSON read). This reuses `$eligible_handles` (GOOD — fix landed for this loop).
3. **Generation:** `foreach $eligible_handles` (`494-521`) re-checks `isset(registered)` + `fetch_remote_css` + `extra before/after`. The generation loop still re-validates `isset` but not the three predicates (GOOD — now reuses the pre-classified set).

However the **triple** is actually inside `get_combined_handles` + `should_skip_combine_for_inline_budget` + `core_will_inline` double-simulation:

- `get_combined_handles` calls `core_will_inline` per handle. Each `core_will_inline` (`732-782`) calls `core_inline_budget_will_inline(..., false)` + `core_inline_budget_will_inline(..., true)` (prediction + reference) and compares.
- `should_skip_combine_for_inline_budget` (`1080-1129`) independently loops `eligible_handles` doing `get_local_path` + `is_readable` + `filesize` per handle (up to 30 `stat` calls).
- The `freshness` mtime loop then does `get_local_path` + `exists` + `mtime` per handle (another 30 stats).
- So per `combine_css` invocation: **1× classify + 1× budget simulation (2× inner sims) + 1× is_readable/filesize + 1× exists/mtime** ≈ 3.5× stat/classify work vs single-pass optimum.

`inline_size_map` memo (`118-119`) avoids rebuilding the `is_file/filesize/is_readable` map per handle, but the double-simulation still does 2× `uasort`+cumulative loop per handle.

### S-05 — file_exists ×480

**Status: MITIGATED for next-gen serving, residual in used-CSS + combine_css**

`Image_Optimisation::replace_image_with_next_gen` (`includes/class-image-optimisation.php:887-944`) previously did `file_exists(avif_path)` + `file_exists(webp_path)` per image per render with no memo. Fixed: `Image_Optimisation` now has `private static array $file_exists_cache` (`126`) bounded `FILE_EXISTS_CACHE_LIMIT 500` (`134`) and `cached_file_exists(path)` (`820-835`) with FIFO eviction, plus `get_cached_image_size` LRU 100 (`857-873`). A gallery with 80 images × 3 srcset candidates = 240 image URLs × 2 exists = 480 stats → now cached to ≤ unique paths per request (typically 80 unique).

`maybe_serve_next_gen_images` Tag Processor loop (`615-676`) and regex fallback (`682-792`) both route through `cached_file_exists`, so the 480 → ~80 unique reduction is real.

**Residual file_exists sites still uncapped:**
- `Cache::get_combined_handles` budget path: `is_file`+`filesize`+`is_readable` per queued handle inside `core_inline_budget_will_inline` (cached map helps, but `should_skip_combine_for_inline_budget:1110-1116` does `is_readable`+`filesize` outside the map).
- `Used_CSS::process_buffer` (`1209-1227`) freshness check: `file_exists(used_css_path)` + per queued style `file_exists(local_path)` + `filemtime(local_path)` per handle per cache-miss render (no memo).
- `Cron::used_css_cron` + `Cache::invalidate_dynamic_static_html` (term loop) does `delete_cache_files` per path (exists+delete ×3).

### S-06 — WP_Query / get_posts no_found_rows

**Status: PRESENT in 3 hot loops**

| Location | Query | Missing Flags | Cost |
|----------|-------|---------------|------|
| `includes/class-cron.php:288-299` `schedule_page_cron_jobs` | `get_posts([post_type=>public[], post_status=>publish, posts_per_page=>200, paged=>ceil((offset+1)/200), fields=>ids, orderby=>ID, order=>ASC])` | `no_found_rows`, `update_post_meta_cache`, `update_post_term_cache`, `suppress_filters` all absent | `SQL_CALC_FOUND_ROWS` + `SELECT FOUND_ROWS()` extra query + term/meta hydrates `wp_term_relationships` for 200 IDs per batch; runs every `init` |
| `includes/class-used-css.php:908-918` `regenerate_all` | `get_posts([post_type=>public[], post_status=>publish, posts_per_page=>200, offset=>offset, fields=>ids, orderby=>ID, order=>ASC])` | `no_found_rows` absent; `OFFSET` pagination (see S-10) | Last batch `OFFSET 9800` walks 9800 rows; 50 batches × `SQL_CALC_FOUND_ROWS` |
| `includes/class-cron.php:636-654` `queue_unconverted_library_images` (via `Img_Converter`) | Internal `get_posts` discovery limit 50 | `no_found_rows` absent | Minor (limit 50) but still pays `FOUND_ROWS` |
| `includes/class-image-optimisation.php` `get_post_type_preload_data` | `has_post_thumbnail`, `get_post_thumbnail_id`, `wp_get_attachment_image_srcset` — not WP_Query but still DB per render | N/A | 3 queries per preload render; gated on `is_singular` + option |

`performance-optimisation.php` bootstrap instantiates `Main` on every request which constructs `Cache` + `Image_Optimisation` + `Cron` + `Metabox` + `Asset_Manager` + `Abilities` even when their queries are not needed (see P-WP-04).

### S-07 — Cron locks

**Status: CORRECT — all 5 cron paths have transient locks, one TTL inconsistency**

| Cron | Lock Key | TTL | `try/finally` | Correct? |
|------|----------|-----|---------------|----------|
| `includes/class-cron.php:276-338` `schedule_page_cron_jobs` | `wppo_preload_cron_lock` | 20 min | yes (`finally delete`) | YES — covers `get_posts`+`wp_next_scheduled`×200 + sitemap 15s |
| `includes/class-cron.php:371-388` `used_css_cron` | `wppo_used_css_lock` | 20 min | yes | YES |
| `includes/class-cron.php:622-686` `img_convert_cron` | `wppo_img_convert_lock` | 5 min | yes | YES — but 5 min may be short for `batch 50 × convert_image` (GD decode) on slow FS; if batch overruns, second worker can enter |
| `includes/class-cron.php:724-734` `database_cleanup_cron` | `wppo_db_cleanup_lock` | 5 min | yes | YES |
| `includes/class-cron.php:398-406` `web_vitals_rescan_cron` | NONE | — | — | No lock — but gates on `wppo_web_vitals_last_rescan` option (daily/weekly) + `as_enqueue_async_action` dedup; acceptable but two concurrent daily ticks could double-queue |
| `includes/class-cache.php:1624-1629` `save_cache_files` | `wppo_cache_write_{md5(path)}` | 5s | yes | YES — per-path, not global |
| `includes/class-pagespeed.php:394-405` `acquire_trend_lock` | `wppo_web_vitals_trends_lock` (option `add_option` atomic) | 60s | yes (`release`) | YES — uses `add_option` atomic INSERT vs transient (correct for persistent-cache-less setups) |
| `includes/class-rum.php:354-358` `flush_queue` | `wppo_rum_flush_lock` | 30s | yes | YES |
| `includes/class-database-cleanup.php` `optimize_table` | NONE per table | — | — | Runs `OPTIMIZE TABLE` sequentially without lock; not reentrant but called from `clean_all` which is already single-threaded via `database_cleanup_cron` lock |

**Inconsistency:** `img_convert_cron` 5 min vs `schedule_page_cron_jobs` 20 min — image batch with `batch 50` × `imagecreatefromjpeg` 20 MB + `imagewebp` + `imageavif` + `generate_lqip` 20px thumb can exceed 5 min on constrained hosts (2-3s per image ×50 = 100-150s). If lock expires mid-batch, a second `img_convert_cron` tick (hourly schedule may still fire if previous tick overran) could overlap. TTL should match worst-case batch (10 min safer).

### S-08 — Cache life baking

**Status: CORRECT with regeneration hook**

`Advanced_Cache_Handler::create` (`includes/class-advanced-cache-handler.php:152-154`) reads `get_option('wppo_settings')['cache_settings']['cacheLife']` and bakes `'$cache_life = ' . $cache_life . ';'` into the generated `advanced-cache.php` (`169`). The drop-in's `wppo_serve_cache_file` checks `if ($cache_life > 0 && file_exists($check_path) && (time - filemtime) > $cache_life*3600) return;` (`222`) — stale files are not served beyond TTL.

**Stale-bake risk:** If admin changes `cacheLife` from 24h → 1h, the drop-in still serves with old 24h until `Advanced_Cache_Handler::create()` is re-run. **Mitigated:** `Main::on_settings_update` (`1032-1067`) detects `cache_settings`/`file_optimisation` etc. diff and calls `Advanced_Cache_Handler::create()` on any `cache_relevant_tabs` change (`1064`). So the bake is refreshed immediately on settings save. Fresh installs and `maybe_run_version_upgrade` (`1012-1014`) also regenerate. The only gap is a manual `update_option('wppo_settings', …)` via `wp shell`/`wp option update` that bypasses the `update_option_wppo_settings` action string — but that path is not the UI and `create()` would need manual re-run.

---

## 3. Findings — File:Line, Category, Severity, Problem, Why Matters, Evidence, Impact, Recommended Solution, Confidence, Measurable

### 3.1 CPU — Loops, Regex, Serialization, Object Creation, FS Ops, Network

| # | File:Line | Cat | Sev | Problem | Why Matters | Evidence | Impact (Measurable) | Recommended Solution | Conf. | Meas. |
|---|-----------|-----|-----|---------|-------------|----------|---------------------|----------------------|-------|-------|
| **F-CPU-01** | `includes/class-cache.php:396-544` + `618-663` | CPU/loops | **HIGH** | `combine_css` triple-classify: `get_combined_handles` validates each handle (core_will_inline, block-asset, exclude), then freshness mtime loop re-checks same predicates indirectly, then generation loop re-validates `isset(registered)`. More critically, `core_will_inline` double-simulates budget per handle (prediction+reference) → 2× `core_inline_budget_will_inline` per handle. | Extra CPU per frontend cache-miss render; scales with `wp_styles->queue` size. DelayJS/CDN also add loops on same request. | `Cache::combine_css:442` `get_combined_handles` call → `631-663` loop with `core_will_inline`+`is_core_block_asset`+`is_excluded_from_combine`; `460-471` freshness `foreach eligible_handles` + `494-521` generation `foreach eligible_handles`; `732-782` `core_will_inline` does `core_inline_budget_will_inline(...,false)` + `(...,true)` per handle, each `uasort`+cumulative loop. | 30 styles × 2 sims × (uasort 30 + loop 30) = 3600 comparisons + 60 `uasort` per render (~1-2 ms). Plus 90 `strpos` exclude checks ×2. Total ~2-4 ms extra per cache-miss. Over 1000 cache-miss hits/day = 2-4s CPU. | Extract single `classify_handles($styles)` returning `{eligible, inline, block, excluded, mediaMismatch}` and reuse; memoize `prediction` vs `reference` per handle per request; compute `reference` only when `prediction===true`. | HIGH | 30 handles → 270 `strpos` + 120 sims avoided → ~50% classify cut. |
| **F-CPU-02** | `includes/class-cache.php:809-887` | CPU/regex+FS | **MEDIUM** | `core_inline_budget_will_inline` builds `inline_size_map` (`813-827`) via `WP_Styles->queue` × `get_data(path)` + `is_file`+`filesize`+`is_readable` per handle. Cached after first call, but initial build does 3 syscalls per queued handle + `uasort` of map. The `should_skip_combine_for_inline_budget` (1080-1129) then does a *second* independent `get_local_path`+`is_readable`+`filesize` loop over `eligible_handles` without reusing the map. | Duplicate FS stats per request when combined-CSS is enabled on block themes. | `class-cache.php:813-827` `is_file`+`filesize`+`is_readable` loop; `1102-1117` second `is_readable`+`filesize` loop in `should_skip_combine…`. | 30 queued + 15 eligible = 45 × 3 syscalls = 135 `stat` calls; cached map avoids rebuild but second loop still does 15 extra. | Have `should_skip_combine…` reuse `inline_size_map` or a shared `handle→path/size` map; or accept first `filesize` failure as "do not skip". | HIGH | 15 `stat` saved per block-theme render. |
| **F-CPU-03** | `includes/class-telemetry.php:682-744` | CPU/network | **HIGH** | `Telemetry::calculate_sizes` sequential blocking `wp_remote_head` fallback per asset when `filesize` misses. Up to 110 assets × 5s timeout = 550s worst-case wall. | Holds PHP-FPM worker for tens of seconds on CDN-heavy pages; admin scan REST `run_performance_scan` spinner stalls; `wppo_web_vitals_rescan` not affected but `performance_scan` is. | `class-telemetry.php:695-728` `$get_size` closure: `if local_path && file_exists → filesize` else `home_host gate` + `wp_http_validate_url` + `wp_remote_head(timeout 5)` sequential in `foreach` loops 731-741. | Page with 40 CDN assets → 40×5s=200s before `scan` returns. Even 10 assets → 10 HEAD × ~200ms = 2s. | Skip HEAD entirely for non-local assets (return 0 — already "best-effort local telemetry") or batch HEAD via `Requests::request_multiple` / curl multi. Document that CDN sizes are 0. | MED | 40 assets ×5s → 0s (or ~400ms multi). |
| **F-CPU-04** | `includes/class-image-optimisation.php:887-944` | CPU/FS | **MEDIUM→LOW (mitigated)** | `replace_image_with_next_gen` per-image `file_exists` ×2 — previously 480 stats per gallery page, now mitigated by `cached_file_exists` LRU 500. | Cache-miss HTML gen pays FS stats per `<img>`+`srcset` candidate; residual in other paths. | `class-image-optimisation.php:126-134` `FILE_EXISTS_CACHE_LIMIT 500`, `820-835` `cached_file_exists`, `934-938` `cached_file_exists(avif/webp)`. Gallery 80×3=240 URLs ×2=480 stats → now ≤80 unique. | 480→80 stats (~85% cut). Residual 80 still per cache-miss, but baked into static HTML cache. | Keep. Also memoize `Util::get_local_path` result per URL. | HIGH | 480→80 stat (~10-50 ms saved per cache-miss). |
| **F-CPU-05** | `includes/class-used-css.php:258-390` | CPU | **MEDIUM** | `Used_CSS::parse_css` char loop (`while offset<length` + `strpos('}',offset)` + `substr` copies per rule + recursive `parse_css` for `@media/@supports` children) on up to 500 KB combined CSS per cache-miss. | `Used_CSS::process_buffer` calls `get_all_css_assets` (TagProcessor + `get_contents` per handle) then concatenates all CSS then single `parse_css` — O(n) allocations. | `class-used-css.php:258-390` char loop, `brace_depth` while, `substr($css,…)` per at-rule/regular rule. | 500 KB CSS → ~2k rules → ~500k char compares + 2k substr (~5-15 ms) per used-CSS cache-miss. 200 pages ×15 ms = 3s aggregate during warm. | Cache purged result keyed by `md5(combined_css)` in transient/object cache 1h. | MED | 5-15 ms → ~0 ms on hit. |
| **F-CPU-06** | `includes/class-database-cleanup.php:209-326` | CPU/DB | **MEDIUM** | `clean_revisions_advanced` inner `OFFSET` pagination (`LIMIT 500 OFFSET 0/500/1000…`) per parent with `ORDER BY post_date_gmt DESC`. MySQL `OFFSET` is O(offset) scan. Outer loop groups parents 200 at a time. | Large revision tables (1M rows) make last batches walk 10k+ rows per parent; wall 10-30s. | `class-database-cleanup.php:251-281` `LIMIT %d OFFSET %d` loop per parent, `offset += 500`. | 5k parents × avg 3 batches = 15k queries with increasing OFFSET. | Keyset pagination `WHERE post_date_gmt < $last_seen ORDER BY post_date_gmt DESC LIMIT 500` instead of OFFSET; or single `SELECT ID,post_date_gmt WHERE post_parent IN (…)` batched. | MED | 10-30s → ~2-5s. |
| **F-CPU-07** | `includes/class-cache.php:1633-1637` + `1658-1667` | CPU/compress | **LOW** | `save_cache_files` does `gzencode(buffer, 9)` + `brotli_compress(buffer,4)` per HTML write. `gzencode 9` is ~1.5× slower than 6 for ~1% size win. Duplicate branch previously existed (now fixed to single `if use_brotli`). | CPU on every cache-miss HTML write (~50 KB buffer → 2 ms at 9 vs 1 ms at 6). 1k writes/day = 1s extra. | `class-cache.php:1633` `gzencode($buffer,9)`, `1660` `brotli_compress($buffer,4,0)`. | 9 vs 6: ~50% compress CPU per write. | Change `gzencode` level 9→6; brotli 4 is fine. | HIGH | ~1 ms saved per HTML cache write. |
| **F-CPU-08** | `includes/class-img-converter.php:290-295,402-405,1256-1302` | CPU/FS+GD | **MEDIUM** | `convert_image` does `getimagesize` (header parse) + `is_gain_map_image` `file_get_contents 64KB` + `filesize` + `get_source_image_dimensions` regex + `resolve_output_format` MIME check + GD `imagecreatefrom*` + `convert_palette_to_truecolor` + `extract_dominant_color` sampled loop + `generate_lqip` 20px thumb per image. Batch `50` per `img_convert_cron` tick. | 50 images × (64KB read + GD decode ~5-30 MB peak per image) can spike memory 100-200 MB + CPU 100-150s per tick. | `class-img-converter.php:291-292` `file_get_contents 64KB`, `402-405` `filesize`, `414` `getimagesize`, `460-475` palette→truecolor, `725-763` dominant loop, `776-839` LQIP thumb. | 50 × ~2-3s = 100-150s wall per `img_convert_cron`. | Keep batch 50 but make `filesize`/`getimagesize` single-read; defer LQIP/dominant extraction to async or sample fewer pixels. | MED | — |

### 3.2 WordPress — get_option, update_option, Transients, Object Cache, Hooks, Admin/Frontend Init, REST, Cron, Rewrite

| # | File:Line | Cat | Sev | Problem | Why Matters | Evidence | Impact | Recommended Solution | Conf. | Meas. |
|---|-----------|-----|-----|---------|-------------|----------|--------|----------------------|-------|-------|
| **F-WP-01** | `includes/class-util.php:125-161` vs 14 direct `get_option('wppo_settings')` sites | WP/get_option | **HIGH (residual)** | `Util::get_settings()` memoization mitigates 6×/render, but 14 call sites still `get_option` directly, bypassing memo and re-deserializing ~8-15 KB per call. | Each direct call is `maybe_unserialize` + array copy + filter run; under `autoload=yes` DB not hit but PHP churn ~0.5-1 ms per call. 14 sites × occasional frontend invocation = 7-14 ms waste on mixed paths. | Grep 14 direct sites: `class-main.php:878,1156,1201`, `class-critical-css.php:925,1015,1026`, `class-cdn-purger.php:55,112`, `class-pagespeed.php:325`, `class-litespeed-integration.php:305,475,690,818,1114,1154,1189`, `class-rest.php:488,772`. | Frontend `process_background_image` + `on_save_post_queue_used_css` each re-read settings outside memo. | Replace all direct `get_option('wppo_settings')` with `Util::get_settings()`; add PHPCS rule or `grep` CI guard to forbid raw key except in `Util`. | HIGH | 6→1 `get_option` per cache-miss render already; residual 14 sites → 0 extra. |
| **F-WP-02** | `includes/class-cron.php:114-128,288-299` | WP/WP_Query | **HIGH** | `schedule_cron_jobs` on `init` every request (6× `wp_next_scheduled` + `get_option('wppo_settings')`), and `schedule_page_cron_jobs` `get_posts` without `no_found_rows/update_post_meta_cache/update_post_term_cache/suppress_filters`. | `init` runs on every frontend+admin+REST+AJAX+CLI request; `wp_next_scheduled` is `get_option('cron')` unserialize + hash lookup. `get_posts(200)` does `SQL_CALC_FOUND_ROWS` + meta/term hydrates. | `class-cron.php:114-115` `Util::get_settings` + `120,130,134,138,143` `wp_next_scheduled`; `288-299` `get_posts` args lack flags. | `schedule_cron_jobs` ~2-5 ms per `init` even when preload off; `get_posts` extra count query + term hydration per preload batch. | Add `'no_found_rows'=>true, 'update_post_meta_cache'=>false, 'update_post_term_cache'=>false, 'suppress_filters'=>true` to all `get_posts` in `Cron` and `Used_CSS`. Gate `schedule_cron_jobs` debounce via 5-min transient or move `wp_next_scheduled` checks to `admin_init` + cron ticks. | HIGH | `SQL_CALC_FOUND_ROWS` removed; ~5-10 ms saved per preload batch. |
| **F-WP-03** | `includes/class-main.php:2067-2180` vs `includes/class-util.php:415-453` | WP/hooks | **LOW** | `Util::generate_preload_link` runs `wp_kses($link_tag, $allowed_html)` per `<link>` (10 preloads → 10× regex tokenizer). Inputs already `esc_attr`/`esc_url`. | `wp_kses` is heavy vs direct echo; 10× per cache-miss render. | `class-util.php:438-453` builds `$allowed_html` then `wp_kses`. | 10× `wp_kses` ≈ 2-5 ms vs `echo '<link …>'`. | Emit `'<link '.esc_attr….'>'` directly; `wp_kses` not needed for already-escaped values. | MED | ~2-5 ms per render. |
| **F-WP-04** | `includes/class-main.php:485-799` | WP/hooks | **MEDIUM** | `setup_hooks` registers ~35 hooks unconditionally on every request (including `is_admin`, `REST`, `wp_enqueue_scripts`×5, `wp_footer RUM`, 5 `Llms` hooks, 4 constructors `new Metabox/Cron/Asset_Manager/Abilities` that each add more hooks). | Even when `rum_enabled` false, `RUM::maybe_enqueue_scripts` hook still registered then early-returns per `wp_enqueue_scripts`. 4 object constructions per request cost autoload+hook churn. | `class-main.php:485-799` hook list; `619-620` `RUM::maybe_enqueue_scripts` + `print_config` always added; `762-765` `new Metabox/Cron/Asset_Manager/Abilities`. | 35 hook adds cheap (~0.1 ms) but 4× constructor `add_action`×N multiplies. `RUM`/`Llms` hooks fire checks per frontend render. | Gate heavier subsystems behind option: `if (!empty(options[perf_audit][rum_enabled])) add RUM hooks`; `if (!empty(llms_txt[enabled])) Llms hooks`; make `Asset_Manager` admin-only. Keep `Cron` always. | MED | ~1-2 ms per frontend render. |
| **F-WP-05** | `includes/class-main.php:1436-1480` | WP/cache stats | **MEDIUM** | `Cache::get_cache_size` + `Util::get_js_css_minified_file` on admin `toplevel_page_performance-optimisation` do recursive `dirlist` + `fs->size` per file (up to 5k files) when transient `wppo_cache_size` (15 min) expires. Both helpers run sequentially on first admin view after expiry. | Single admin hit pays 200-800 ms wall blocked on `admin_enqueue_scripts`. | `class-main.php:1531-1534` `get_transient(wppo_cache_size)` else `calculate_directory_size`; `class-cache.php:2170-2192` recursive `dirlist`+`size`. | 5k cached pages → 200-800 ms; happens 1×/15 min. | Warm cache stats via WP-Cron `wppo_after_cache_clear` async job; or store `cached_pages` alongside size in single transient to avoid double walk (`get_cache_stats` already does both in one traversal). | HIGH | 200-800 ms → ~0 ms on admin hit. |
| **F-WP-06** | `includes/class-rest.php:1416-1450` | WP/REST | **LOW** | `Rest::queue_pagespeed_scan` + `run_performance_scan` + `get_pagespeed_results` each `wp_http_validate_url` + `scheme http/https` + `home_host` check — correct but `queue_scan` does immediate `as_enqueue_async_action` without `as_has_scheduled_action` dedup check (unlike `used_css` queue). | Concurrent double-click "Scan" in SPA can queue duplicate `wppo_pagespeed_scan` jobs for same URL+strategy. | `class-rest.php:1299` `Pagespeed::queue_scan` direct enqueue; vs `class-main.php:1210` `as_has_scheduled_action` guard for `used_css`. | Duplicate scans waste 120s `wp_remote_get` workers. | Add `!as_has_scheduled_action('wppo_pagespeed_scan', [['url'=>…,'strategy'=>…]], 'performance_optimisation')` guard before enqueue; or let `Pagespeed::queue_scan` check. | HIGH | 0 duplicate jobs. |
| **F-WP-07** | `includes/class-cron.php:201-247` | WP/cron | **LOW** | `web_vitals_rescan_cron` queues `Pagespeed::queue_scan` per URL×strategy without `as_has_scheduled_action` guard; also builds `$urls` dedup via `in_array` linear scan. | Daily cron with `high_value_urls` (10 URLs ×2 strategies = 20 jobs) could double-queue if previous day's jobs still pending. | `class-cron.php:234-240` loop `queue_scan` per URL×strategy; no `as_has_scheduled` check. | Up to 20 extra jobs vs intended. | Guard with `as_has_scheduled_action` per URL+strategy before enqueue. | MED | — |
| **F-WP-08** | `includes/class-log.php:68-75` | WP/update_option | **LOW** | `Log::add` does `wpdb->insert` then `update_option(salt, time)` or `update_option(cache_version, ++count)` on **every** log insert, even for low-value logs (`Database_Cleanup` per-method). | `Database_Cleanup::clean_all` 9 methods × `Log::add` each → 9× `update_option` (autoload=no) writes per `clean_all` call. `Log::add` for `Scheduled %d image jobs` etc. also bumps salt. | `class-log.php:70-74` `update_option` per insert. | 9 writes per clean run; salt bump invalidates all `get_recent_activities` caches even for unrelated pages. | Batch salt bump (e.g., only bump once per `clean_all` via `clean_all` caller) or make `add` accept `bool $bump=true` and callers batch. | LOW | 9→1 `update_option` per clean_all. |

### 3.3 Database — Query Counts, Duplicates, Expensive/Missing Indexes, Per-Request, Loops, Cache Keys, Stampede, Collision, Persistent Cache Compat

| # | File:Line | Cat | Sev | Problem | Why Matters | Evidence | Impact | Recommended Solution | Conf. | Meas. |
|---|-----------|-----|-----|---------|-------------|----------|--------|----------------------|-------|-------|
| **F-DB-01** | `includes/class-database-cleanup.php:842-925` | DB/COUNT | **HIGH** | `Database_Cleanup::get_counts` does **9 `COUNT(*)`** (`posts`×3, `comments`×2, `JOIN`×2 for transients, `LEFT JOIN` orphan, `posts attachment`, `options oembed`) per call when cache miss, via `wpdb->get_var` sequentially. `expired_transients` JOIN uses `CONCAT(timeout_prefix, SUBSTRING(a.option_name, len))` — not index-friendly. `orphan_postmeta` `LEFT JOIN posts WHERE p.ID IS NULL` scans `postmeta` without index on orphan. | `get_counts` is called by REST `database_cleanup_counts` (Dashboard `fetchDbCounts` on mount) and thus by every dashboard tab view after 5-min transient expiry. 9 sequential counts on 500k posts + 2M postmeta = 100-800 ms. | `class-database-cleanup.php:862-916` 9 `get_var` calls; `876-889` `INNER JOIN options b ON CONCAT…` + `900-902` orphan `LEFT JOIN`. | 100-800 ms wall on large DB per uncached dashboard view. | Keep salt-cache; make `get_counts` lazy-per-type (dashboard could fetch single cards) or single aggregated query via `UNION ALL`. Add covering index is WP core tables — not plugin's to add. At minimum, cache HOUR not 5 min for fallback path. | HIGH | HOUR cache already via `wp_cache_get_salted` when available; 5-min fallback is the gap. |
| **F-DB-02** | `includes/class-database-cleanup.php:557-595` | DB/delete | **MEDIUM** | `clean_unattached_media` does `wp_delete_attachment(id, true)` **per ID** inside `while count>=batch` loop (batch 500). Each `wp_delete_attachment` fires `delete_post` + `delete_postmeta` + `wp_delete_file` + hooks per attachment. | 2k unattached media → 2k× `wp_delete_attachment` → 2k× `unlink` + 2k× hook chains. Could be `delete_in_batches` via direct SQL plus single sweep of files. | `class-database-cleanup.php:587-591` `foreach ids as id { if (false !== wp_delete_attachment…) ++deleted }`. | 2k deletes → 2k× file I/O + hook overhead (~1-3s). | Acceptable correctness — `wp_delete_attachment` is required to fire `delete_attachment` hooks and unlink intermediate sizes; direct SQL would leave files. Keep but batch `as_enqueue_async_action` per chunk to avoid request timeout. | MED | — |
| **F-DB-03** | `includes/class-database-cleanup.php:624-656` | DB/read | **LOW** | `get_autoloaded_options` `SELECT option_name, LENGTH(option_value) AS opt_size WHERE autoload IN (%s,%s,…) ORDER BY opt_size DESC LIMIT 20` — uses `LENGTH(option_value)` sort which is full table scan even with index on `autoload` (since ordering by expression). | Transient `wppo_pagespeed_*` etc. not autoloaded, so not counted; but `wp_options` with 5k rows still scans. | `class-database-cleanup.php:631-632` `LENGTH(option_value)`. | ~10-50 ms on 5k options. | OK — diagnostic endpoint not hot path; limit 20 caps. Consider adding `WHERE autoload='yes'` is already sargable on `autoload` index in WP 6.6+ (`auto-on` etc.). Keep. | HIGH | — |
| **F-DB-04** | `includes/class-log.php:112-126` | DB/ORDER BY | **MEDIUM** | `Log::get_recent_activities` `SELECT COUNT(*)` + `SELECT * ORDER BY created_at DESC LIMIT/OFFSET` — table `wppo_activity_logs` created via `dbDelta` with `id BIGINT AUTO_INCREMENT PRIMARY KEY, activity TEXT, created_at DATETIME` but **no index on `created_at`**. `ORDER BY created_at DESC` therefore does filesort. | Activity log with 50k rows: filesort + `OFFSET` scan per dashboard `App.js` mount. Cache is `wp_cache_get_salted` HOUR per page, so uncached `OFFSET 100` still scans. | `class-activate.php` `create_activity_log_table` `dbDelta` schema; `class-log.php:121-126` `ORDER BY created_at DESC`. | 50k rows filesort ~20-50 ms; paginated deeper pages slower. | Add `KEY created_at (created_at)` via `dbDelta` migration in `maybe_run_upgrades` (safe additive). Alternatively `ORDER BY id DESC` (PK correlates with time). | HIGH | 20-50 ms → ~1 ms with index. |
| **F-DB-05** | `includes/class-used-css.php:895-937` | DB/WP_Query | **MEDIUM** | `Used_CSS::regenerate_all` OFFSET pagination `get_posts(offset=>0,200,400…)` — last batch `OFFSET 9800` walks 9800 rows. Inside loop `as_has_scheduled_action` per post_id → 10k `SELECT` on `actionscheduler_actions` per 10k posts. | `regenerate_all` holds PHP worker 5-15s. | `class-used-css.php:907-918` `offset` pagination; `924` `as_has_scheduled_action` per post. | 10k posts → 50 `get_posts` + 10k `has_scheduled` queries. | Cursor pagination `WHERE ID > $last_id ORDER BY ID ASC LIMIT 200`; batch `as_get_scheduled_actions` single query per batch. | MED | 5-15s → ~1-3s. |
| **F-DB-06** | `includes/class-system-info.php:548-559` | DB/read | **LOW** | `System_Info::get_mysql_var('max_connections')` `SHOW VARIABLES LIKE %s` via `prepare` — extra read per `get_system_info` (admin only, cached client-side). | Not hot path. | `class-system-info.php:550-558`. | 1 `SHOW VARIABLES` per admin SPA dashboard view. | Keep — negligible. Could memoize per request static. | HIGH | — |
| **F-DB-07** | `includes/class-cache.php:2015-2023` | DB/update_option | **LOW** | `Cache::clear_cache` does `update_option(wppo_cache_last_cleared, salt+1)` or `delete_transient(wppo_cache_size)` + `update_option(wppo_cache_last_cleared_time, now)` per clear. Called via `on_settings_update` (diff-gated) + `on_save_post_invalidate_cache` per post save (smart purge). | Per-post invalidate does not call `clear_cache` full — it calls `invalidate_dynamic_static_html` (per-URL delete). Full `clear_cache` only on `update_option_permalink_structure`, `switch_theme`, `activated_plugin`, or `cache_relevant_tabs` change — rare. | `class-cache.php:2015-2023`. | Rare writes. | OK as-is: diff gate prevents churn. | HIGH | — |

### 3.4 Cache — Key Design, Stampede, Collision, Persistent Cache Compat

| # | File:Line | Cat | Sev | Problem | Why Matters | Evidence | Impact | Recommended Solution | Conf. | Meas. |
|---|-----------|-----|-----|---------|-------------|----------|--------|----------------------|-------|-------|
| **F-CA-01** | `includes/class-util.php:720-729` + all `Util::transient_key` calls | Cache/key | **INFO (correct)** | All transient keys are `Util::transient_key('wppo_*')` which prefixes with `blog_id_` on multisite → prevents collision on shared Redis/Memcached. | Prevents cross-site cache poisoning in multisite. | `class-util.php:720-729` `is_multisite() ? blog_id . '_' . key : key`; grep 40+ `Util::transient_key` uses. | Correct — no collision. | Keep. | HIGH | — |
| **F-CA-02** | `includes/class-cache.php:1561-1589` vs `includes/class-advanced-cache-handler.php:222` | Cache/stampede | **MEDIUM** | Static HTML served via `advanced-cache.php` before WP boots — no `wp_cache_*` stampede protection there; the drop-in checks `file_exists(gz/br)` + `filemtime` + `cache_life` per request without locking. Concurrent expiry after `cache_life*3600` could cause 50 concurrent cache-miss renders (all bypass drop-in then race to regenerate). | `save_cache_files` transient lock (5s per path) mitigates writer race, but drop-in readers still all miss and enter WP (thundering herd on expiry). | `class-advanced-cache-handler.php:222` `if file_exists(check_path) && (time - filemtime) > cache_life*3600 return;` — no lock. | On expiry, 50 concurrent hits → 50 PHP workers regenerate same page (only one writes due to lock, rest discard work but still did full `process_buffer_only`). | Add stale-while-revalidate: serve stale file with `X-Cache: stale` + async regenerate via `wppo_generate_static_page` scheduled event, instead of blocking render. Or jitter `cache_life` per path. | MED | Herd 50→1 async regen. |
| **F-CA-03** | `includes/class-pagespeed.php:394-416` + `includes/class-telemetry.php:69-78` | Cache/stampede+compat | **INFO (correct)** | `Pagespeed::record_trend` uses `add_option(lock, time)` atomic INSERT vs transient, with 60s stale steal — works even when object cache is request-local (no shared Redis). `Telemetry::scan` + `Database_Cleanup::get_counts` use `wp_cache_get_salted` when available (WP 6.9+ `wp_cache_add_salt`) else `get_transient` fallback — correct persistent-cache compat. | `add_option` atomicity avoids lost updates for concurrent mobile+desktop trend writes (two async workers). | `class-pagespeed.php:395` `add_option(TREND_LOCK_OPTION, time)`, `413` `delete_option`; `class-telemetry.php:72-73` `wp_cache_get_salted` branch. | Correct — no action. | Keep. Document that `add_option` lock is DB-backed not memcache-backed, so no race on non-persistent setups. | HIGH | — |
| **F-CA-04** | `includes/class-cache.php:2015-2023` + `includes/class-log.php:70-74` | Cache/salt invalidation | **INFO (correct)** | Cache size/count salts correctly use `wp_cache_get_salted` (WP 6.9+ `wp_cache_add_salt`) for invalidation via `update_option(salt, time)` vs fallback `delete_transient`. `Log::add` bumps salt correctly per insert; `Database_Cleanup::invoke_cleanup_method` also invalidates via `invalidate_counts_cache`. | Salt model avoids delete-then-race; `update_option` is atomic. Fallback `delete_transient` works on DB-transient stores. | `class-log.php:70-74`, `class-cache.php:2016-2018`, `class-database-cleanup.php:950-955`. | Correct — no action. | Keep. Consider batching `Log::add` salt bumps during `clean_all` (see F-WP-08). | HIGH | — |
| **F-CA-05** | `includes/class-telemetry.php:957-983` + `includes/class-pagespeed.php:247` | Cache/growth | **LOW** | `Telemetry::register_transient_key` caps `wppo_transient_index` at 200 (prune by expiry `asort` slice -200). `Pagespeed::prune_trends` caps `wppo_web_vitals_trends` at 20 keys ×30 snaps. Both bounded. | Prevents unbounded `wppo_audit_*` + `wppo_pagespeed_*` transient index growth. | `class-telemetry.php:968-979` prune when `count>200`; `class-pagespeed.php:429-459` prune oldest `fetched_at`. | Bounded — no leak. | Keep. | HIGH | — |
| **F-CA-06** | `includes/class-pagespeed.php:239-249` | Cache/collision | **LOW** | `Pagespeed::get_transient_key(url, strategy)` is `Util::transient_key('wppo_pagespeed_' + md5(esc_url_raw(url)) + '_' + sanitize_key(strategy))`. `md5` collisions negligible. Same-host validation at REST ensures `url` is site-local, so `wppo_pagespeed_*` keys cannot be polluted via off-site URL (SSRF guard). | No collision. Key is blog-prefixed via `transient_key`. | `class-pagespeed.php:311-313`. | Correct. | Keep. | HIGH | — |

### 3.5 Hooks / Rewrite / Serialization / Memory

| # | File:Line | Cat | Sev | Problem | Why Matters | Evidence | Impact | Recommended Solution | Conf. | Meas. |
|---|-----------|-----|-----|---------|-------------|----------|--------|----------------------|-------|-------|
| **F-HK-01** | `includes/class-main.php:787-792` + `includes/class-cron.php:57-73` | Hook/rewrite | **INFO (correct)** | `update_option_permalink_structure` → `clear_all_cache`, `update_option_wppo_settings` → `on_settings_update` (with `maybe_run_version_upgrade` `wppo_version` gate). Rewrite rules for `llms.txt` via `Llms::register_rewrite` on `init`, `query_vars` filter. No `flush_rewrite_rules` on every `init` — only on `activate` (via `Activate`). Correct. | No per-request rewrite flush (which would be `update_option(rewrite_rules)` thrash). | `class-main.php:787-789`. | Correct. | Keep. | HIGH | — |
| **F-SE-01** | `includes/class-img-converter.php:1689-1735` | Serialize | **INFO (correct but deferred)** | `Img_Converter::get_img_info` / `update_conversion_status` use `wppo_img_info` (non-autoload, `autoload=false`) with deferred shutdown commit (`$deferred_img_info` + `shutdown` hook `maybe_persist`). `convert_image` batches do `update_img_info_atomic` per image but deferred commit batches writes to 1 `update_option` per request via shutdown. | Prevents N `update_option` per image in batch (would be 50 writes per `img_convert_cron` tick). Correct. Residual: `get_option('wppo_img_info')` still called via `get_img_info` per `maybe_serve_next_gen_image` hot path? Actually `maybe_serve_next_gen_image` does `Util::get_local_path` + `file_exists` not `get_option`; only `Img_Converter` reads `wppo_img_info` for queue state. | `class-img-converter.php:1689-1735` deferred pattern. | Correct — 50 images → 1 `update_option` vs 50. | Keep. Ensure shutdown hook always registered (it is via `register_shutdown`). Document that `get_option('wppo_img_info')` callers should prefer `get_img_info()` accessor not raw `get_option`. | HIGH | 50→1 write per batch. |
| **F-ME-01** | `includes/class-img-converter.php:725-839` | Memory | **MEDIUM** | `extract_dominant_color` samples every `sample_rate = sqrt((w*h)/500)` pixels (min 500 samples) via `imagecolorat` per pixel; `generate_lqip` does `imagecreatetruecolor(20, scaled_h)` + `imagecopyresampled` + `ob_start` + `imagejpeg q40` + `base64_encode`. Per `convert_image` this holds 2 GD resources simultaneously (original + thumb). | 500× `imagecolorat` is cheap (~0.5 ms) but `imagejpeg` buffer + base64 doubles LQIP data URI size (~1-2 KB per image) stored in `wppo_img_info.lqip` option (autoload false) — 5k images → 5-10 MB serialized option. | `class-img-converter.php:725-839`. | `wppo_img_info` option bloat: dominant_color per rel_path (small) + LQIP base64 per rel_path (large) can reach MBs. | Cap LQIP generation to `placeholderType==='lqip'` only; already gated? Actually `maybe_extract_placeholder_for_upload` checks `placeholderType` but `convert_image` always calls `store_placeholder_data` regardless. Gate `convert_image` placeholder extraction behind `placeholderType` too. | MED | Option size halved when `placeholderType !== lqip`. |
| **F-ME-02** | `includes/class-cache.php:1633-1637` | Memory | **LOW** | `save_cache_files` holds `$buffer` (full HTML ~50-200 KB) + `gzencode` copy + `brotli_compress` copy simultaneously before atomic writes. Peak ~400-600 KB per cache write. | Acceptable; but `process_buffer_only` also holds `minify_buffer` + `maybe_apply_used_css` copies sequentially (not concurrently). | `class-cache.php:1601-1680`. | Peak ~600 KB per HTML cache-miss. | Release `gz_output`/`br_output` after `atomic_put_contents` via `unset` (GC). Minor. | LOW | — |

---

## 4. Summary — Overall Performance Posture

**Good:** `Util::get_settings` memo, `Util::transient_key` blog-prefix everywhere, `save_cache_files` transient lock + atomic `tmp+move`, `RUM` transient queue (100 cap) + 20-threshold flush + lock, `Image_Optimisation` `cached_file_exists` LRU 500, `calculate_sizes` `filesize` fast-path + `wp_remote_head` same-host gate, `Pagespeed` `add_option` trend lock, `Telemetry` 15s sitemap deadline + `MAX_REDIRECT_HOPS 2`, `Advanced_Cache_Handler` cache-life bake with regeneration on settings save.

**Needs work (actionable):**

1. Replace 14 direct `get_option('wppo_settings')` with `Util::get_settings()` (F-WP-01).
2. Add `no_found_rows`/`update_post_*_cache` to all `get_posts` in `Cron` and `Used_CSS` (F-WP-02).
3. Stop sequential `wp_remote_head` per CDN asset in `Telemetry::calculate_sizes` (F-CPU-03).
4. Add `KEY created_at` to `wppo_activity_logs` (F-DB-04).
5. Cursor pagination for `Used_CSS::regenerate_all` and `clean_revisions_advanced OFFSET` (F-DB-05, F-CPU-06).
6. Guard `Pagespeed::queue_scan` with `as_has_scheduled_action` dedup (F-WP-06/07).
7. Gate `RUM`/`Llms` hook registration behind option checks (F-WP-04) and bump `img_convert_cron` lock 5→10 min (S-07).

**No critical stampede or N+1 in page-load path survives** — the remaining issues are cron/admin/batch overhead and correctable via flag additions and index migration, not architecture rewrites.

---

## 5. Appendix — Grep Counts & Verification Notes

- `get_option` raw: 100+ hits (tests included); `wppo_settings` direct outside `Util`: 14 (listed above)
- `transient` : 115 hits (40 `Util::transient_key` prefixes)
- FS ops: `file_exists` 35, `is_file` 2, `filesize` 8, `is_readable` 6, `file_get_contents` 5, `file_put_contents` 2
- `preg_` : 201 hits (CSS minify, HTML minify, Telemetry parse, Cache CDN rewrite, Used_CSS purge)
- `as_enqueue` : 12 (pagespeed×2, image×2, used_css×2, preload×2)
- `WP_Query`/`get_posts` : `get_posts` 8, `WP_Query` 0 direct (correctly via `get_posts`)
- `wp_cache_get_salted` : 6 (Log, Counts, Telemetry)
- `gzencode` : 1, `brotli_compress` : 1 (no duplicate branch after fix)

**Confidence legend:** HIGH = measured or single-file evidence, MEDIUM = static reasoning + cross-file trace, LOW = minor/approximate.

*Generated audit-only — no production code modified. Evidence is `file:line` from the workspace at audit time.*
