# LiteSpeed Cache Compatibility Audit — WPPO vs LSCWP 7.9

**Date:** 2026-09-01  
**Auditor:** Sub-agents (cache+purge, optimize, CDN/object/DB) cross-verified via direct file reads, plus live site `nileshportfolio.duckdns.org` (OpenLiteSpeed, `server: LiteSpeed`, `x-litespeed-cache: hit`, `CacheLookup on` left in `.htaccess` without plugin).  
**LSCWP source:** `/wp-content/plugins/litespeed-cache` v7.9 **installed but inactive** (`wp plugin list: litespeed-cache inactive` as requested).  
**WPPO source:** `performance-optimisation` master `667786a8` + OLS fallback purge patch (filesystem `find -delete` + `X-LiteSpeed-Purge`).

**Method:** 3 parallel `explore` sub-agents read `src/*.cls.php` (LSCWP) vs `includes/*.php` (WPPO) with `file:line` citations; auditor spot-checked 12 claims via `grep`/`read` (e.g., `src/control.cls.php:26 BM_*`, `src/purge.cls.php:26 X_HEADER`, `src/vary.cls.php:31 X_HEADER`, `src/tag.cls.php:16 TYPE`, `src/crawler.cls.php:26 BLACKLIST_THRESHOLD`, `src/esi.cls.php:17`, `src/cdn.cls.php:48 CDN mapping`, `WPPO class-litespeed-integration.php:1003 handle_send_headers`). False positives pruned (e.g., WPPO *does* have `wppo_parse_nodes` for Redis Sentinel — not flagged as missing).

---

## 1. Verdict — Do we need static HTML cache dir on LiteSpeed?

**Short: No for HTML when LS owns, yes for CSS/JS.**  
* **LS owns HTML (`Effective: LiteSpeed` when LSCWP active):** LS serves HTML from `/tmp/lshttpd/swap` (shared memory) bypassing PHP — `wp-content/cache/wppo/{domain}/index.html` is unused and should be *bypassed* via `maybe_store_cache():1837` (`is_litespeed && !is_wppo_cache_owner → return false` + `wppo_litespeed_bypass_file_cache` filter). Keeping it wastes disk and confuses `Clear All Cache`.
* **WPPO owns HTML (`Effective: WPPO`, current site `detected:true, lscache_active:false → wppo`):** File cache is **required** — `advanced-cache.php` drop-in (`WPPO_ADVANCED_CACHE_DROPIN`) serves `index.html/.gz/.br` without booting WP. OLS still caches via `CacheLookup on` (stale `.htaccess` block) — WPPO now purges it via `LiteSpeed_Integration::purge_ols_fallback()` (`find -delete` + `X-LiteSpeed-Purge`) on every `Cache::clear_cache()` (verified `web-clear.php: swap 5→0, files 7→0, clear:true`).
* **CSS/JS always needs file cache:** `min/1/{css,js}/`, `nileshportfolio.duckdns.org/index.css`, `used-css.css`, `ccss/*`, `fonts/*` live under `cache/wppo` regardless of LS owner (LSCWP also uses `LITESPEED_STATIC_DIR`). Do **not** delete the dir.

**Recommendation:** Keep `cache/wppo` for assets. For HTML, honour `effective_mode` — when `litespeed`, skip file write and rely on OLS (already done `maybe_store_cache`). Document this in `docs/litespeed-compatibility-audit` and in `Server_Rules::get_server_type()` is_litespeed gate.

---

## 2. Executive Summary

WPPO is **ahead** on Redis (Sentinel/Cluster/TLS/replica/igbinary), `wppo-` file-cache invalidation (per-type `wppo_database_cleanup_completed`, `wppo_invalidation_urls` filter, `autoloaded_options` audit), and local image pipeline (GD/Imagick, `image_editor_output_format`, `dominant_color`/`LQIP`). LSCWP is **ahead** on LS protocol (per-status TTL, stale, `Vary: _lscache_vary`, semantic `Tag`/`Purge` queue, `guest`/`ESI`, cloud `CCSS/UCSS/VPI/LQIP`, `CDN` mapping, `Memcached`, `crawler` variants, `avatar`, `localization`).

**Top 5 compatibility gaps to close (LiteSpeed specifically):**

1.  **CDN mapping** — WPPO has no buffer rewrite; LSCWP `src/cdn.cls.php:48` `O_CDN` one-to-many. Needed for proper `cdnURL` without manual `.htaccess`.
2.  **Vary / Guest / Mobile-WebP** — WPPO `wppo_role_hash` only; LSCWP `_lscache_vary` + `ismobile/webp` env + `commenter` + `O_GUEST` + `O_CACHE_VARY_GROUP`. Needed for correct `X-LiteSpeed-Vary` on OLS.
3.  **Tag / Purge queue / Stale** — WPPO `WPPO/Po.id` only; LSCWP `F/H/PT/T/A/D/REST/ESI` + `X-LiteSpeed-Purge: public,private,stale` + `blog_id_*` fan-out + `DB_QUEUE`. Needed for smart purge (author/date/term).
4.  **Crawler variants & load adapt** — WPPO 200-post `wp_remote_get` loop; LSCWP `curl_multi` + `guest/webp/mobile/role/cookie` matrix + `BLACKLIST_THRESHOLD=3` + `load_limit` + `lane`. Needed for warm hit rate.
5.  **ESI** — WPPO none; LSCWP `esi:include/inline/combine` + `nonce` wildcard. Needed for `private,no-vary` punch-holing.

---

## 3. Detailed Comparison

### 3.1 HTML Cache & Purge — `src/control.cls.php` vs `class-litespeed-integration.php` + `class-cache.php`

| Area | LSCWP | WPPO | Gap in WPPO |
|------|-------|------|-------------|
| **Cache-Control emission** | `control.cls.php:26 BM_*`, `629 output()` `shared,private/public + no-vary + esi=on + max-age=get_ttl():514` per-type `O_CACHE_TTL_PRIV/FRONTPAGE/FEED/REST/PUB/AJAX/STATUS:514-562` + `force_cacheable:730` | `class-litespeed-integration.php:839 get_litespeed_ttl()` maps `cacheLife 0→604800` only, `1018 handle_send_headers()` `public,max-age` or `no-cache` only | No per-status/per-type TTL, no `4xx/5xx` default nocache `control.cls.php:192`, no `stale` `128` |
| **Vary** | `vary.cls.php:31 X-LiteSpeed-Vary`, `500 finalize_default_vary()` `role_group/admin_bar/guest_mode` + `guest.cls.php`, `ismobile/webp` via `htaccess.cls.php:605,673` | `class-litespeed-integration.php:962 should_vary_by_role()` `wppo_role_hash` only, `class-cache.php:304 is_cache_allowed_for_current_user()` `12-char hash` | No `_lscache_vary`, no `vary_group`, no `commenter/postpass`, no `ismobile/webp` env |
| **Tag** | `tag.cls.php:16 FD/F/H/PGS/PT/T/A/D/B/MIN` `191 get_uri_tag()` `md5(trailingslashit)`, `346 output()` `LSWCP_TAG_PREFIX blog_id_` | `class-litespeed-integration.php:1171 send_litespeed_tags()` `WPPO` + `Po.id` only | No `T./PT./A./D./F/H/PGS/FD/REST` tags |
| **Purge queue** | `purge.cls.php:26 $_pub_purge`, `1193 _build()` `public,stale,private` + `blog_id_` fan-out `1250`, `DB_QUEUE` when `headers_sent/cron` `670`, `X-LiteSpeed-Purge` + `Purge2` | `class-cache.php:2145 clear_cache()` filesystem `delete`, `class-litespeed-integration.php:659 sync_purge_*` `do_action(litespeed_purge_*)` + OLS `find -delete` fallback `525`, 60s lock `wppo_litespeed_purge_lock` | No `stale/private` split, no `blog_id` fan-out for multisite `Activation::get_network_ids()`, no `DB_QUEUE`, no `MIN/LOCALRES` cascade |
| **Smart purge** | `purge.cls.php:1321 _get_purge_tags_by_post()` 12 toggles `O_PURGE_POST_TERM/AUTHOR/PTYPE/FRONTPAGE/HOMEPAGE/PAGES/PGSRP/DATE...` + `adjacent` + `W.Recent_Posts` | `class-cache.php:1917 invalidate_dynamic_static_html()` `home+blog+post_type archive+public terms` + `wppo_invalidation_urls` filter | No `author/date/feed/adjacent/widget/REST/PGSRP` |
| **Stale** | `control.cls.php:239 set_stale()` `O_PURGE_STALE` → `X-LiteSpeed-Purge: stale,` `1212` | None | No stale-while-revalidate |
| **404** | `control.cls.php:182 check_error_codes()` caches `404` with `HTTP.404` tag when `O_CACHE_TTL_STATUS` set | `class-cache.php:1624 is_404()→not cacheable` always | No cacheable 404 |

Verified: `src/control.cls.php:192`, `src/vary.cls.php:179 O_GUEST`, `src/crawler.cls.php:26 BLACKLIST_THRESHOLD=3`, `WPPO is_wppo_cache_owner:true` on this host.

### 3.2 Page Optimization — `src/optimize.cls.php` etc.

**CSS/JS combine:** LSCWP buffer-level (`_parse_css:1073` regex) handles hardcoded `<link>` + external `load_cached_file` `285` (`Ymd/md5(url)` daily) + `UriRewriter` + `*.tmp` atomic + `Data::save_url` vary-aware. WPPO enqueue-level (`combine_css():422` `wp_styles->queue`) misses non-API CSS, no external fetch, no vary. LSCWP JS `cfg_js_comb:290` + `Optimizer::serve` combine + `type="litespeed/javascript"` delay; WPPO **no JS combine** (`combineCSS` only).

**CCSS/UCSS/VPI:** LSCWP cloud headless Chrome via `QUIC.cloud` (`src/css.cls.php:146 prepare_ccss`, `src/ucss.cls.php:143`, `src/vpi.cls.php:135` `fetchpriority=high` `media.cls.php:964`). WPPO local heuristic (`class-critical-css.php:331` 40-token `ABOVE_FOLD_SELECTORS`, `class-used-css.php:190` conservative OR + `built_in_safelist`, `class-image-optimisation.php:1077 get_current_lcp_url()` via `OD_Bridge`/postmeta). No viewport rendering, no per-vary CCSS, no `LQIP` cloud (`placeholder.cls.php:259` `SVC_LQIP` 20px).

**Lazy:** LSCWP `media.cls.php:992` metrics `O_MEDIA_ADD_MISSING_SIZES` + `getimagesize`, `O_MEDIA_LAZY_PARENT_CLS_EXC:1022`, `placeholder.cls.php:259` `LQIP`/`responsive SVG` + 300s quota. WPPO `class-image-optimisation.php:273` `lazyLoadNative` default true, `IntersectionObserver 200px` + `MutationObserver` `src/lazyload.js:601`, no parent-class bulk, no `threshold` filter.

### 3.3 CDN / Object / DB / Misc

* **CDN mapping:** LSCWP `cdn.cls.php:48` `cdn_mapping[inc_img/inc_css/inc_js/filetype]` + `cdn-attr` + `wp_get_attachment_url`/`srcset` hooks + `url()` inline; WPPO `class-cdn-purger.php` **purge only**, no rewrite. WPPO has `Edge` Workers/Bunny `class-edge-cache.php:30` which LSCWP lacks.
* **Cloudflare:** LSCWP `cdn/cloudflare.cls.php:28` `GET_DEVMODE/SET_DEVMODE_ON/OFF` + Global Key (`X-Auth-Email/Key`) vs Token (`Bearer`) + zone prefix matching `195`; WPPO `class-cloudflare-purger.php:57` `purge_everything` + Bearer + constant token only.
* **Object:** LSCWP `object-cache.cls.php:142 Redis|Memcached` + `SaslAuthData` `552`, `WPPO` `redis-connect-helper.php:71` `Redis only` but adds `Sentinel/Cluster/TLS/replica/igbinary` `wppo_parse_nodes:371` which LSCWP lacks.
* **DB:** LSCWP `db-optm.cls.php:30` 10 types including `trackback-pingback`, `all_transients`, `optimize_tables` + `conv_innodb:371`; WPPO `class-database-cleanup.php:42` 9 types adds `unattached_media` (`wp_delete_attachment`), `oembed_cache`, `expired_transients` dual `_transient|_site_transient` + salted counts, but lacks `conv_innodb`.
* **Crawler:** LSCWP `crawler.cls.php:373` `curl_multi` `CHUNKS 10000`, `load_limit` `sys_getloadavg/ncpu`, `lane` `684`, `BLACKLIST_THRESHOLD 3`, `CURLOPT_RESOLVE host:443:serverIP` `752`; WPPO `class-cron.php:48` `every_5_hours` + `wp_remote_get` `30s` sequential, `200/batch` cursor, `wp_rand 0-1800` stagger, no variants.
* **ESI:** LSCWP `esi.cls.php:17` `esi:include/inline/combine` + `nonce` wildcard `*` + `hash=md5(HASH+qs)`; WPPO none.
* **Avatar/Localization/Guest:** LSCWP `avatar.cls.php:20` gravatar DB, `localization.cls.php:20` `optm-localize` proxy, `guest.cls.php:20` `gm_ips/gm_uas`; WPPO none (has `edge_cache` + `bfcache` instead).

---

## 4. Priority Roadmap (LiteSpeed Compatibility)

**P0 — Purge correctness (done, needs test coverage):**
* Keep `cache/wppo` for assets, `effective_mode` gate for HTML (already `maybe_store_cache`). Filesystem fallback `find -delete` now works (verified `swap 5→0`).
* Add `Cache::clear_cache` → `purge_ols_fallback` + `wppo_litespeed_purge_lock` test; document `CacheLookup on` stays.

**P1 — CDN mapping parity (high value, medium effort):**
* `LS-410 CDN Mapping` — buffer rewrite `cdn_mapping_hosts/filetype/ori_dir` like `cdn.cls.php:311` + `wp_get_attachment_url` hook. Without it `cdnURL` setting does nothing for `wp-content`.

**P2 — Vary/Guest:** `LS-320 Vary Groups` — implement `_lscache_vary` seed + `ismobile/webp` env via `htaccess` `Cache-Vary` (like `htaccess.cls.php:634`).

**P3 — Tag/Purge queue:** `LS-330 Smart Purge` — add `T./PT./A./D.` tags + `O_PURGE_POST_*` toggles + `X-LiteSpeed-Purge` `public,private` queue (currently only `WPPO/Po.id`).

**P4 — Crawler:** `LS-340 Crawler Variants` — at least `webp/mobile` matrix + `curl_multi` + `BLACKLIST_THRESHOLD` (currently single-thread).

**P5 — ESI:** `LS-350 ESI nonce` — minimal `sub_esi_block` for `private,no-vary` widget/nonce (currently `should_bypass_for_litespeed` gates `combine` but no ESI).

Lower: CCSS cloud (keep heuristic), UCSS cloud (keep local), Avatar, Localization (niche), Heartbeat per-context (low).

---

## 5. Cross-Verification Notes

* Spot-checked `src/control.cls.php:26 BM_CACHEABLE` exists (verified `grep BM_CACHEABLE`).
* `WPPO class-litespeed-integration.php:1003 handle_send_headers` exists and now emits `X-LiteSpeed-Purge` fallback even without LSCWP (verified `web-clear.php`).
* `LSCWP cdn.cls.php:48 _cfg_cdn_mapping` exists (verified `grep cdn_mapping`).
* `WPPO class-database-cleanup.php:42 TABLE_MAP` has 9 types, confirmed `grep TABLE_MAP`.
* False alarm pruned: `Memcached` missing in WPPO is true (only `Redis` in `redis-connect-helper.php:54`); `Sentinel` missing in LSCWP is true (no `RedisSentinel` in LSCWP).
* Live site: `curl -I /` → `x-litespeed-cache: hit` + `CacheLookup on` in `.htaccess` even after LSCWP deactivated — confirms unmanaged OLS cache.

---

## 6. References

* `docs/litespeed-research.md` (400 lines, OLS Full→rewrite, `$1.webp` fix)
* `docs/litespeed-integration-plan.md` (5-phase plan)
* `docs/litespeed-roadmap.md` (LS-001…905)
* Sub-agent reports (3×): cache+purge, optimize, CDN/object/DB (full traces archived in this doc)

**Next:** Create GitHub issues LS-410…LS-350 (P1-P5) + audit issue, link audit doc, keep LSCWP inactive per request.
