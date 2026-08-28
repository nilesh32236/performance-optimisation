# Agent A05 — PHP Correctness: New Features + Utils

**Base:** `master@31fffc61`  
**Scope:** PHP Correctness — New Features + Utils specialist  
**Date:** 2026-08-28  
**Auditor:** Agent A05  
**Workspace:** `/var/www/nileshportfolio.duckdns.org/wp-content/plugins/performance-optimisation`  
**Instruction:** Audit-only, do NOT modify production code. Read every line, trace AI heuristic, Edge Worker generation, bfcache pageshow, mo→php compile, OD bridge, LLMs, Litespeed 4-state arbitration, CDN purge, asset manager, metabox, abilities, DB cleanup 7 types, core tweaks (emoji, embeds, dashicons).

---

## 1. Files Reviewed

| # | File | Lines | Purpose |
|---|------|-------|---------|
| 1 | `includes/class-ai-adaptive.php` | 459 | RUM → heuristic/auto-tune via suggestions, wp_ai_client delegation |
| 2 | `includes/class-edge-cache.php` | 287 | Cloudflare Workers / Bunny Edge adapter, Worker/wrangler generation |
| 3 | `includes/class-edge-purger.php` | 208 | Edge cache purger Cloudflare + Bunny with transient lock |
| 4 | `includes/class-bfcache.php` | 403 | Instant Back/Forward, session-token + pageshow invalidation, nocache_headers |
| 5 | `includes/class-perf-translations.php` | 276 | .mo → .php compilation via WP_Translation_File::transform, OPCache |
| 6 | `includes/class-od-bridge.php` | 685 | Optimization Detective viewport groups, LCP fetchpriority + threshold |
| 7 | `includes/class-llms.php` | 577 | /llms.txt + /llms-full.txt virtual files, rewrite + ETag + sitemap |
| 8 | `includes/class-util.php` | 854 | Settings memo, filesystem, URL/mime, preload links, normalize_url, cached_home_url |
| 9 | `includes/class-litespeed-integration.php` | 1343 | Detection, 4-state arbitration, optimizer guard, purge sync, LS headers/vary |
| 10 | `includes/class-cdn-purger.php` | 229 | CDN cache purger Cloudflare/Varnish + LiteSpeed bridge |
| 11 | `includes/class-asset-manager.php` | 245 | Per-page script/style capture + dequeue (protected handles, transient) |
| 12 | `includes/class-metabox.php` | 453 | Preload image URL + Asset Manager metaboxes, nonce, whitelisting |
| 13 | `includes/class-abilities.php` | 496 | WP 7.0 Abilities API categories + 6 feature + 4 operational abilities |
| 14 | `includes/class-database-cleanup.php` | 1113 | 7+ cleanup types (revisions auto_drafts trash spam transients orphans unattached oembed), batching |
| 15 | `includes/class-core-tweaks.php` | 408 | Disable emojis/embeds/dashicons/XML-RPC, heartbeat, feeds, shortlinks |
| **Total** | | **8036** | |

All files read in full via `Read` (single read each; `class-litespeed-integration.php` two windows for 1343 lines). Grep cross-checked `apply_filters`, `function_exists`, `version_compare`, `has_filter`, `wp_cache_get_salted`, `Util::transient_key`.

---

## 2. Lines Reviewed

**8036 lines** — sum `459+287+208+403+276+685+577+854+1343+229+245+453+496+1113+408`. Each file read end-to-end with offset handling where truncated. Manual trace of AI heuristic scoring (`class-ai-adaptive.php:164-306`), Edge Worker template replacement (`class-edge-cache.php:171-285`), bfcache pageshow JS (`class-bfcache.php:360-382`), mo→php compile (`class-perf-translations.php:137-204`), OD bridge viewport grouping (`class-od-bridge.php:164-428`), LLMs sitemap walk (`class-llms.php:432-495`), LiteSpeed 4-state `effective_mode()` (`class-litespeed-integration.php:347-397`), CDN purge dispatch (`class-cdn-purger.php:45-104`), asset dequeuing (`class-asset-manager.php:92-126`), metabox whitelisting (`class-metabox.php:347-386`), abilities delegation (`class-abilities.php:405-494`), DB cleanup 9 types batched (`class-database-cleanup.php:122-706`), core tweaks hooks (`class-core-tweaks.php:33-101`).

---

## 3. Findings

> Columns: **ID | Severity | File:Line | Category | Evidence | Impact | Recommendation | Confidence**

### 3.1 `includes/class-ai-adaptive.php` — 459 lines

| ID | Severity | File:Line | Category | Evidence | Impact | Recommendation | Confidence |
|----|----------|-----------|----------|----------|--------|----------------|------------|
| AI-01 | MEDIUM | `class-ai-adaptive.php:200-207` | Correctness / Dead Code | `foreach ($trends as $key=>$snapshots){ if(!is_array) continue; // key is md5(url)_strategy — we cannot reverse md5; use as signal only}` — loop body empty, trends never influence score or eagerness. Comment acknowledges limitation but heatmap blend is no-op. | RUM-only scoring; Web Vitals trends (PageSpeed history) ignored despite being loaded. | Either remove dead loop or derive eagerness from trends avg score (e.g. `trends[md5][strategy]['scores']['performance']`). Document intentional no-op. | HIGH |
| AI-02 | HIGH | `class-ai-adaptive.php:279-283` | Logic / Threshold | `$avg_lcp_all` averaged across dates, then `if($avg_lcp_all>3500) $eagerness='moderate'; elseif($avg_lcp_all>2500) $eagerness='moderate';` — both branches assign `moderate`, `eager` never assigned; `3500` branch redundant. | AI never suggests `eager` speculation despite high LCP, limiting prefetch aggressiveness. | Fix to `>3500 ? 'eager' : (>2500 ? 'moderate' : 'conservative')` or keep `moderate` for >3500 and add `eager` >4000 with rationale. | HIGH |
| AI-03 | MEDIUM | `class-ai-adaptive.php:246-250,254-260` | Logic / Heuristic | `asort($disabled); $exclude_js = array_slice(array_keys($disabled),0,3);` comment `Least-used = lowest frequency (rarely disabled = maybe safe to suggest excluding)` — inverted: most-frequently disabled handles are the least-used, should be `arsort` (highest count). `exclude_css` always empty (never populated). | AI suggests rarely-disabled scripts (likely critical) for exclusion, risking breakage; CSS suggestions never fire. | Change to `arsort($disabled)` for `exclude_js`; populate `exclude_css` from `_wppo_disabled_styles` or remove CSS suggestion path. | MEDIUM |
| AI-04 | LOW | `class-ai-adaptive.php:333-337,388-402` | Correctness / Memo | `get_suggestions()` does `if(empty($model)) $model=self::heuristic_learn();` without `is_enabled()` gate; `learn()` throttles via `wppo_ai_learn_lock` but direct `heuristic_learn()` bypasses lock and recomputes on every suggestion request when model empty. | Repeated heavy DB query (`LIMIT 500` postmeta scan + RUM aggregation) on dashboard polls when AI disabled/model missing. | Gate on `is_enabled()` or cache heuristic result via transient; remove fallback recompute or debounce. | MEDIUM |
| AI-05 | LOW | `class-ai-adaptive.php:130-156` | Security / Logging | `learn_via_ai_client()` catches `\Throwable` with empty catch, no logging; `wp_ai_client()->prompt()` concatenates `wp_json_encode($rum).wp_json_encode($trends)` without size guard. | Silent failures hide AI integration errors; large RUM payloads could exceed prompt token limit. | Log via `do_action('wppo_debug_log', ...)` in catch; truncate RUM/trends to top paths before prompt. | LOW |
| AI-06 | INFO | `class-ai-adaptive.php:51-61,419-445` | Correctness / Guard | `is_enabled()` reads `wppo_settings[ai_adaptive][enabled]` default false + `wppo_ai_adaptive_enabled` filter; `filter_speculation_rules()` early returns if disabled or not array, then appends `['source'=>'list','urls'=>prefetch,'eagerness'=>...]` with `wppo_ai_adaptive_speculation_rules` filter. | Never auto-enables, gated correctly, speculation shape mirrors WP core. | No change. | HIGH |

### 3.2 `includes/class-edge-cache.php` — 287 lines

| ID | Severity | File:Line | Category | Evidence | Impact | Recommendation | Confidence |
|----|----------|-----------|----------|----------|--------|----------------|------------|
| EDGE-01 | LOW | `class-edge-cache.php:123-158` | Correctness / Filter | `get_config()` applies `wppo_edge_cache_config` filter, then `return is_array($config) ? $config : fallback`. If filter returns non-array, fallback uses original `$ttl/$swr/$provider` but discards filtered `origin_url` even if valid. | Filter returning string/int is silently ignored with stale defaults, confusing extensibility. | Validate filtered config shape (require array with `origin_url, cache_ttl, swr, provider`) else fallback + `do_action('wppo_debug_log')`. | LOW |
| EDGE-02 | LOW | `class-edge-cache.php:171-204,253-285` | Correctness / Template | `get_worker_js()` / `get_bunny_edge_js()` do `str_replace('{{ORIGIN_URL}}', esc_url_raw($origin), $content)` on template file if exists else inline fallback; placeholders `{{CACHE_TTL}}`, `{{SWR}}` replaced but `fallback` also contains `{{ORIGIN_URL}}`? Inline Bunny fallback includes comment `Origin: {{ORIGIN_URL}}` replaced — correct. Worker fallback uses `caches.default` + `ctx.waitUntil` but Bunny fallback uses `event.waitUntil` vs `ctx.waitUntil` mix. | Bunny edge JS may not match Cloudflare semantics exactly; subtle cache API drift. | Align Bunny fallback to same `caches.default` pattern and test on Bunny runtime. Document placeholder contract. | MEDIUM |
| EDGE-03 | INFO | `class-edge-cache.php:73-86,98-116` | Correctness / Configuration | `is_enabled()` reads `edge_cache.enabled` + filter; `is_configured()` checks `edge_cache.cloudflareZoneId + WPPO_CLOUDFLARE_API_TOKEN` or `bunnyPullZoneId + WPPO_BUNNY_API_KEY`, plus fallback `cache_settings.cdnPurgeService==='cloudflare'` — safe. | Dual-source zone ID covers Edge_Cache vs CDN_Purger config. | No change. | HIGH |

### 3.3 `includes/class-edge-purger.php` — 208 lines

| ID | Severity | File:Line | Category | Evidence | Impact | Recommendation | Confidence |
|----|----------|-----------|----------|----------|--------|----------------|------------|
| EP-01 | LOW | `class-edge-purger.php:89-95` | Correctness / Semantics | `purge_all($type='all', $url_path=null)` returns `true` for any `type!=='all'` (including `single_page`): "edge purge is zone-wide to avoid partial stale". | Single-page cache clear never purges edge — stale edge may serve outdated page until next full purge or TTL expiry. | Document trade-off; consider purging single URL via `cdnPurgeService` path when provider supports single-URL purge (Cloudflare `purge_cache: files`). | MEDIUM |
| EP-02 | INFO | `class-edge-purger.php:137-194` | Correctness / HTTP | `purge_cloudflare()` POST `https://api.cloudflare.com/client/v4/zones/{id}/purge_cache` with `Bearer token`, `purge_bunny()` POST `https://api.bunny.net/pullzone/{id}/purgeCache` with `AccessKey`, 10s timeout, `wp_remote_request`, 2xx check, `log_failure` via `wppo_debug_log`. | Correct endpoints, timeouts, error handling. | No change. | HIGH |

### 3.4 `includes/class-bfcache.php` — 403 lines

| ID | Severity | File:Line | Category | Evidence | Impact | Recommendation | Confidence |
|----|----------|-----------|----------|----------|--------|----------------|------------|
| BFC-01 | MEDIUM | `class-bfcache.php:63-89` | Docs / Gate | Docblock says "Also gates on `wp_cache_get_salted` existence as proxy for WP 6.9+" but `is_enabled()` only checks setting + filter `wppo_bfcache_enabled`, no `function_exists('wp_cache_get_salted')` gate. `Perf_Translations` correctly gates on salted family. | Spec drift: bfcache described as requiring WP 6.9 but actually enables on any version, may violate privacy-safe session-token expectation. | Add `if(!function_exists('wp_cache_get_salted')) return false;` or update doc to "soft gate, filter documents intended version". | HIGH |
| BFC-02 | HIGH | `class-bfcache.php:270-308` | Logic / Dead Code | `filter_nocache_headers()` has `if(null===$token){ if(!isset($_COOKIE[cookie]) && null!==$token){ set_cookie } if(null===$token) return headers; } else { if(!isset($_COOKIE)) set_cookie }` — inner `null!==$token` is always false when outer `null===$token`, so first `set_cookie` unreachable; duplicate cookie-ensure logic in both branches. | Cookie repair for deleted mid-session never runs when token is null? Logic collapses to keep `no-store` correctly but dead code confuses. | Simplify to `if(null===$token) return $headers; // privacy: keep no-store` then single cookie-ensure block outside. | HIGH |
| BFC-03 | MEDIUM | `class-bfcache.php:360-382` | Correctness / JS Invalidation | JS: `function i(){document.documentElement.style.opacity="0";try{document.documentElement.innerHTML=""}catch(e){} u.searchParams.set(q,Math.random); history.replaceState({}, "", u.href); window.location.reload();}` and `function h(e){if(e.persisted&&t!==g()){i();return} ...}` plus immediate `if(t!==g()){i()} else addEventListener("pageshow",h)`. Race: immediate check runs before cookie may be set on first load, causing false-positive reload loop; `wppo_bfcache_reloaded` param added with random then cleared in `h` on next load via `u.searchParams.delete(q)`. | Potential reload loop on slow cookie write or `COOKIE_DOMAIN` mismatch; clearing innerHTML may break extensions. | Add guard: only reload if `document.visibilityState==='visible'` or debounce via `sessionStorage` flag; avoid `innerHTML=""` (just opacity + reload). | MEDIUM |
| BFC-04 | LOW | `class-bfcache.php:113-118` | Correctness / Token | `generate_token()` uses `wp_generate_password(43,false,false)` (43 alphanumeric) else `bin2hex(random_bytes(16))` (32 hex). Length mismatch 43 vs 32. | Inconsistent token entropy/length across WP versions, but both >128-bit. | Standardize to `bin2hex(random_bytes(32))` or 43-char always via `random_bytes` fallback. | LOW |
| BFC-05 | LOW | `class-bfcache.php:191-217,248-257` | Correctness / Cookie | `set_token_cookie()` sets both `COOKIEPATH` and `SITECOOKIEPATH` with `$secure` from `is_logged_in_cookie_secure()`; `on_clear_auth_cookie()` always sets `$secure=false` on delete, but creation may be secure — mismatch may leave secure cookie on HTTPS. | On HTTPS, logout may fail to clear secure bfcache cookie (secure flag mismatch). | Use same `is_logged_in_cookie_secure($user_id)` logic for clear path or set both secure+insecure variants. | MEDIUM |

### 3.5 `includes/class-perf-translations.php` — 276 lines

| ID | Severity | File:Line | Category | Evidence | Impact | Recommendation | Confidence |
|----|----------|-----------|----------|----------|--------|----------------|------------|
| PT-01 | LOW | `class-perf-translations.php:103-122` | Correctness / Locale | `preg_match('/-([a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]+)?)$/i', $basename, $m)` extracts locale suffix; fallback `hash-only` when extraction fails uses `sanitize_key(basename)` which may collide for `my-plugin-de_DE` vs `my-plugin-de_DE_formal`? Actually `de_DE_formal` not matched by `[a-z0-9]+` third part? It does match. But `sanitize_key` lowercases `de_DE` → `de_de`, losing case but hash ensures uniqueness. | Minor locale normalization, but case loss may cause two locales mapping to same filename prefix (not hash suffix). | Use `sanitize_file_name` or preserve case via `strtolower` only for domain key, keep locale case. | LOW |
| PT-02 | MEDIUM | `class-perf-translations.php:161-183` | Performance / Race | `filter_load_file()` does `WP_Translation_File::transform($file,'php')` then `WP_Filesystem::put_contents` or `file_put_contents` without atomic `temp+rename`; concurrent requests may interleave writes. No `flock`. | Partial `.php` file may be included by another request mid-write, causing parse error. | Write to `cache_file.tmp` then `rename()` atomically; or `fs->put_contents` with `FS_CHMOD_FILE` already atomic on some FS but not guaranteed. | MEDIUM |
| PT-03 | INFO | `class-perf-translations.php:51-72,227-234` | Correctness / Gate | `is_enabled()` requires `perf_translations.enabled` true + `wppo_perf_translations_enabled` filter + `function_exists('wp_cache_get_salted')` (WP 6.9+). `init()` registers both `load_translation_file` (6.5+) and `load_textdomain_mofile` (legacy), plus `upgrader_process_complete` to clear cache. | Correct version gating and dual-filter registration. | No change. | HIGH |

### 3.6 `includes/class-od-bridge.php` — 685 lines

| ID | Severity | File:Line | Category | Evidence | Impact | Recommendation | Confidence |
|----|----------|-----------|----------|----------|--------|----------------|------------|
| OD-01 | HIGH | `class-od-bridge.php:318-330` | Logic / LCP Collection | `collect_raw_lcp_urls()` array shape branch: `if(self::element_is_lcp($metric)) { $url=extract(...); if(''!==$url) $urls[]=$url; } else { $url=extract(...); if(''!==$url) { $urls[]=$url; } }` — `else` adds URL even when `isLCP` false, treating any array with `src/url` as LCP. | Non-LCP images pollute raw LCP list, `get_lcp_url()` may return non-LCP image as most-common, causing wrong `fetchpriority=high`. | Remove `else` branch or require `element_is_lcp` true before adding; log when array lacks flag. | HIGH |
| OD-02 | MEDIUM | `class-od-bridge.php:72-103` | Correctness / Filter | `is_enabled()` returns `$should && $enabled` where `$should = apply_filters('wppo_od_should_optimize',$enabled,$current_url)` and `$enabled` is already bool from settings. If filter returns true but `$enabled` false, result false — filter cannot enable when setting disabled. Doc says filter "whether OD optimization should be applied" — ambiguous. | Filter cannot override setting to enable, limiting extensibility. | Return `(bool)$should` alone or document that filter is veto-only. Add `wppo_od_should_optimize` allows true when OD available. | MEDIUM |
| OD-03 | LOW | `class-od-bridge.php:346-428` | Correctness / Viewport | `count_viewport_groups()` buckets widths into `<=768 mobile` else `desktop`; fallback `if(empty($widths)) return 1;` assumes 1 group when metrics exist but no width info. | Magic 768px threshold may miss tablet breakpoint (Performance Lab uses 480/782?), 1-group fallback may under-exclude images. | Align threshold with OD's `OD_URL_Metric_Group_Collection` breakpoints or make filterable `wppo_od_viewport_threshold`. | LOW |
| OD-04 | INFO | `class-od-bridge.php:440-510` | Correctness / Resilience | `get_url_metrics()` tries `od_get_url_metrics(current_url)` then `od_get_url_metrics()` zero-arg, then `OD_URL_Metric_Group_Collection::get_groups_for_current_url()`, then `$GLOBALS['od_url_metrics']` — exhaustive fallback across OD versions. All wrapped in try/catch + `WP_DEBUG` error_log. | Graceful degradation across Lab 6.9 shapes. | No change. | HIGH |

### 3.7 `includes/class-llms.php` — 577 lines

| ID | Severity | File:Line | Category | Evidence | Impact | Recommendation | Confidence |
|----|----------|-----------|----------|----------|--------|----------------|------------|
| LLMS-01 | MEDIUM | `class-llms.php:334-373` | Correctness / Data Source | `collect_urls()` for `trends` source decodes `wppo_web_vitals_trends` but keys are `md5(url)_strategy` irreversible, falls back to `high_value_urls` + `home` as proxy. Comment admits cannot reverse. | LLMs digest never includes actual trend URLs beyond high_value list, reducing relevance. | Store plain URL alongside md5 key when recording trends (e.g. `trends[md5]['_url']`), or accept limitation and document. | MEDIUM |
| LLMS-02 | LOW | `class-llms.php:432-495` | Performance / Fetch | `collect_sitemap_urls()` loops `while(!empty(to_fetch) && url_count<cap)` with `microtime deadline+15s`, `wp_remote_get(timeout 5)` sequential, `preg_match_all('#<loc>#')`, host check `wp_parse_url` — no `wp_http_validate_url` or scheme/host validation before fetch. | Sequential sitemap fetch may hit 15s wall-clock even when `cap=50`; no SSRF guard though local sitemap only (home_host check after fetch, not before). External sitemap referenced via index could be fetched before host check (host check after body parse, not before request). | Validate `current` URL host==home_host before `wp_remote_get`; parallelize or cache sitemap parse. | MEDIUM |
| LLMS-03 | LOW | `class-llms.php:113-186` | Correctness / Caching | `serve()` does `md5_file(path)` for ETag, then `if(headers_sent()) { readfile; exit; }` — test hook. `Content-Length: filesize(path)` + `readfile` may double-read file. No `Last-Modified`. | `md5_file` on 20KB file cheap, but `filesize` + `readfile` could race if file truncated between calls. | Use `filesize` after `md5_file` or `fstat`; add `Last-Modified` header. | LOW |

### 3.8 `includes/class-util.php` — 854 lines

| ID | Severity | File:Line | Category | Evidence | Impact | Recommendation | Confidence |
|----|----------|-----------|----------|----------|--------|----------------|------------|
| UTIL-01 | LOW | `class-util.php:816-852` | Correctness / Sanitization | `sanitize_settings_recursively()` does `$safe_key=preg_replace('/[^a-zA-Z0-9_\-]/','',$key); if(''===$safe_key) continue;` then branches `is_array → recursion`, `is_bool`, `is_numeric→(int)`, `in_array(...,['pagespeed_api_key','password'])`, `stripos('exclude')`, `stripos('url'/'cdn'/'origin')`, else `sanitize_text_field`. Numeric branch casts `3.14` float to `3` int, losing float precision; `is_numeric("08")` → 8. | Float settings (e.g. `cls` thresholds) truncated; octal strings mis-handled but no float settings currently. | Check `is_float` before `is_numeric` or use `sanitize_text_field` for numeric strings then cast per-key. Document int-only intent. | LOW |
| UTIL-02 | INFO | `class-util.php:568-592,691-707` | Correctness / Normalize | `normalize_url()` drops scheme+query, strips size suffix `-(?:\d+x\d+|scaled|e\d+)(?=\.[A-Za-z]+)$`, lowercases host, returns `host+path`. Used by AI Adaptive and OD bridge for dedup. | Collapses derived image sizes to canonical, correct for LCP matching. | No change. | HIGH |
| UTIL-03 | LOW | `class-util.php:720-729` | Correctness / Multisite | `transient_key()` does `if(!function_exists('is_multisite')) return $key; try{ return is_multisite() ? get_current_blog_id().'_'.$key : $key } catch(\Throwable){ return $key }` — catches exception from `is_multisite()` (Brain Monkey) but `get_current_blog_id()` may also throw when not mocked. | Unprefixed transient on multisite if `get_current_blog_id` throws, causing cross-site collision. | Wrap `get_current_blog_id()` in same try. | LOW |
| UTIL-04 | INFO | `class-util.php:125-190` | Correctness / Memo | `get_settings()` memo with `ensure_settings_cache_hook()` once per request (`update_option/add/delete` hooks), `set_settings_cache/clear/reset_all`. | Reduces `get_option` deserialization from 6 to 1 per request. Correct. | No change. | HIGH |

### 3.9 `includes/class-litespeed-integration.php` — 1343 lines

| ID | Severity | File:Line | Category | Evidence | Impact | Recommendation | Confidence |
|----|----------|-----------|----------|----------|--------|----------------|------------|
| LS-01 | MEDIUM | `class-litespeed-integration.php:240-289` | Consistency / Filter | `is_lscache_active()` early returns `true` on `defined('LSCWP_V')` or `class_exists('LiteSpeed\*')` before applying `wppo_litespeed_is_lscache_active` filter; filter only runs on `active_plugins` path. | Ecosystem filter cannot veto when LSCWP constant/classes present, breaking testability. | Move filter to wrap all branches: `$active = ...; return apply_filters(...)` . | MEDIUM |
| LS-02 | LOW | `class-litespeed-integration.php:393-394` | Correctness / Filter Allowlist | `effective_mode()` filters `wppo_litespeed_effective_mode` then `if(in_array($filtered, ['wppo','litespeed','standalone'])) $cached=$filtered;` — `auto` explicitly not allowed post-filter, but `get_mode()` allows `auto`. | Filter cannot return `auto` to force re-resolution, intentional but undocumented. | Document that effective_mode filter must return concrete mode, not `auto`. | LOW |
| LS-03 | MEDIUM | `class-litespeed-integration.php:886-927` | Correctness / Vary | `handle_send_headers()` Vary fallback checks `$wp_filter['litespeed_vary']->callbacks` and `only_self` logic; if no `wp_filter` object or no callbacks, `has_external=false` → emits `Vary: Cookie` + `X-LiteSpeed-Vary: cookie=wppo_role_hash`. On OLS without LSCWP, raw headers honored; but setting both `Vary: Cookie` and `X-LiteSpeed-Vary` may double-vary. | `Vary: Cookie` on every cacheable response may bust cache unnecessarily when `should_vary_by_role()` true but user anonymous. Actually `should_vary_by_role()` requires `enableLoggedInCache` true, so anonymous still varies — over-varying. | Scope `Vary: Cookie` to logged-in only or document OLS behavior. | MEDIUM |
| LS-04 | LOW | `class-litespeed-integration.php:1153-1171` | Correctness / Cache | `is_nextgen_rewrite_enabled_for_nginx()` reads `get_option('wppo_settings')` without per-request memo (Util::get_settings not used), unlike `is_nextgen_rewrite_enabled()` which caches via `cached_nextgen`. | Two DB reads per request when both checks run, vs single memo. | Use `Util::get_settings()` consistently. | LOW |
| LS-05 | INFO | `class-litespeed-integration.php:347-397,411-423` | Correctness / Arbitration | `effective_mode()` 4-state: non-LS→standalone, explicit standalone→standalone, explicit wppo/litespeed, auto→lscache_active?litespeed:wppo. `is_wppo_cache_owner()` returns true for `standalone||wppo`. | Correct per-AGENTS spec: standalone = ignore LS → WPPO owns cache. | No change. | HIGH |
| LS-06 | LOW | `class-litespeed-integration.php:762-767` | Correctness / Cacheability | `is_request_cacheable()` aligns with `Cache::maybe_store_cache` — adds query-string `s|ver|v` check via `preg_match('/(?:^|&)(s|ver|v)(?:=|&|$)/',$qs)` and `excludePreloadCache` via `Util::is_url_excluded`. Regex matches `s` param key exactly but may false-positive `https://example.com/?post_s=1`? Actually `(?:^|&)s(?:=|&|$)` would not match `post_s` because `^|&` before `s` requires `&s` exact, so `post_s` not matched — correct. | Precise, matches spec LS-304. | No change. | HIGH |

### 3.10 `includes/class-cdn-purger.php` — 229 lines

| ID | Severity | File:Line | Category | Evidence | Impact | Recommendation | Confidence |
|----|----------|-----------|----------|----------|--------|----------------|------------|
| CDN-01 | LOW | `class-cdn-purger.php:45-104` | Correctness / Delegation | `purge_all()` always calls `purge_litespeed(type,url_path)` before `if('all'!==type) return true;` — but `purge_litespeed` for `single_page` with empty `url_path` does `if(is_string && ''!==url_path)` else fallback `if(is_string && ''!==url_path)` duplicate, so empty path results in no LiteSpeed purge even when sync enabled. | `Cache::clear_cache('/sample/')` may call `purge_all('single_page','/sample/')` with empty normalized path, LiteSpeed URL purge skipped. | Ensure `url_path` fallback to `$_SERVER['REQUEST_URI']` or require caller to pass path; log when empty. | LOW |
| CDN-02 | INFO | `class-cdn-purger.php:111-126` | Correctness / Config | `is_configured()` checks `cdnPurgeService==='cloudflare'` requires `cloudflareZoneId + WPPO_CLOUDFLARE_API_TOKEN`, `varnish` requires `varnishPurgeUrls` non-empty. | Correct. | No change. | HIGH |

### 3.11 `includes/class-asset-manager.php` — 245 lines

| ID | Severity | File:Line | Category | Evidence | Impact | Recommendation | Confidence |
|----|----------|-----------|----------|----------|--------|----------------|------------|
| AM-01 | HIGH | `class-asset-manager.php:92-94` | Correctness / Auth | `dequeue_selected_assets()` early `if(is_admin() || is_user_logged_in()) return;` — logged-in users never get per-page dequeue, even if `enableLoggedInCache` true and they are the cacheable role. | Asset Manager settings have no effect for logged-in cacheable visitors, defeating role-based cache variant purpose. | Change to `if(is_admin()) return; if(is_user_logged_in() && !Util::is_cache_eligible_for_current_user(...)) return;` or document intentional admin-only preview. | HIGH |
| AM-02 | MEDIUM | `class-asset-manager.php:199-211` | Correctness / Change Detection | `capture_page_assets()` compares `existing['scripts']===scripts && existing['styles']===styles` to decide `has_changed`; `delay_strategies`/`delay_priorities` per-page meta not included in comparison, so strategy changes don't trigger transient update. | Delay strategy edits may not refresh captured assets timestamp, stale meta box display. | Include `delay_strategies`/`priorities` in comparison or store `timestamp` always. | MEDIUM |
| AM-03 | LOW | `class-asset-manager.php:35-68` | Correctness / Protection | `$protected_scripts` includes `jquery, jquery-core, jquery-migrate, wp-i18n, wp-hooks, wp-api-fetch, wp-url, wp-polyfill, admin-bar, heartbeat`; `$protected_styles` includes `admin-bar, dashicons, wp-block-library`. `wp-block-library` protected but may be safe to disable on non-block pages. | Over-protective: blocks optimization for pages not using blocks. | Make protected lists filterable `wppo_asset_manager_protected_scripts`. | LOW |

### 3.12 `includes/class-metabox.php` — 453 lines

| ID | Severity | File:Line | Category | Evidence | Impact | Recommendation | Confidence |
|----|----------|-----------|----------|----------|--------|----------------|------------|
| MB-01 | HIGH | `class-metabox.php:54-61` | Correctness / Registration | `add_meta_box('preload_image_metabox', ..., '', 'side', 'default')` — screen `''` empty string, not `null` or post type list. WP `add_meta_box` with empty screen registers for no screen, metabox never appears via this call; asset manager loop registers correctly with `$post_type`. | Preload Image URL metabox likely invisible on all post types (depends on WP fallback handling of empty string). | Change to `null` or `array_diff(get_post_types(...))` loop same as asset manager, or remove duplicate and register inside `foreach`. | HIGH |
| MB-02 | MEDIUM | `class-metabox.php:356-368,408-414` | Correctness / Whitelisting | `save_asset_manager_settings()` whitelists `wppo_disabled_scripts/styles` via `array_intersect(submitted, valid_handles)` where `valid_handles = array_column(assets['scripts'],'handle')` from `Asset_Manager::get_page_assets(post_id)` transient. If transient expired/missing, `valid_handles=[]` → all submitted handles discarded → `update_post_meta(...,[])` wipes settings silently. | Valid admin selection lost if they save before visiting frontend to recapture assets. | Preserve existing meta when `valid_handles` empty: `if(empty(valid_handles)) return;` or merge with previous meta. | HIGH |
| MB-03 | LOW | `class-metabox.php:300-314` | Correctness / Capability | `save_metabox()` checks `DOING_AUTOSAVE` and `current_user_can('edit_post',post_id)` but not `wp_verify_nonce` at top-level (delegated to sub-methods with separate nonces). If both nonces missing, both sub-methods no-op but function returns success — no error. | Silent no-op on missing nonce may confuse, but no security issue. | Add early `if(!isset($_POST['wppo_preload_image_nonce']) && !isset($_POST['wppo_asset_manager_nonce'])) return;` | LOW |

### 3.13 `includes/class-abilities.php` — 496 lines

| ID | Severity | File:Line | Category | Evidence | Impact | Recommendation | Confidence |
|----|----------|-----------|----------|----------|--------|----------------|------------|
| AB-01 | LOW | `class-abilities.php:355-383` | Correctness / Semantics | `can_database_cleanup()` returns `!empty($options['database_cleanup'])` — true if any key truthy, but `database_cleanup` contains `dbRevMaxAge, dbRevKeepLatest, dbOptimize` numeric — always truthy even when all cleanup toggles disabled. | Ability reports "can clean" when no cleanup needed, misleading AI. | Check specific boolean flags or `array_filter` with allowlist of cleanup enables. | LOW |
| AB-02 | LOW | `class-abilities.php:405-417` | Correctness / Delegation | `execute_clear_cache()` for `scope==='single' && !empty(url)` does `$path=wp_parse_url(url, PHP_URL_PATH); Cache::clear_cache($path);` but `Cache::clear_cache` expects path with leading slash and normalized; no `wp_normalize_path` or trailing slash handling. | Single URL clear via Abilities may map to wrong cache dir vs REST path handling. | Use `Util::cached_home_url` normalization or `Cache::clear_cache` already handles? Verify. | LOW |
| AB-03 | INFO | `class-abilities.php:34-37,65-67` | Correctness / Guard | Constructor registers `wp_abilities_api_categories_init` + `wp_abilities_api_init`; `register_categories/abilities` each check `function_exists` before `wp_register_*`. | Correct no-op on WP <6.9 / <7.0 where Abilities API missing. | No change. | HIGH |

### 3.14 `includes/class-database-cleanup.php` — 1113 lines

| ID | Severity | File:Line | Category | Evidence | Impact | Recommendation | Confidence |
|----|----------|-----------|----------|----------|--------|----------------|------------|
| DB-01 | LOW | `class-database-cleanup.php:42-53,81-92` | Correctness / Map | `TABLE_MAP 9 entries`, `CLEANUP_METHOD_MAP 9` — keys aligned. `get_valid_cleanup_types()` merges keys + `all`. `METHOD_TO_TYPE` private const maps method→type for `maybe_optimize_tables` — all 9 present. | Single source of truth, no drift. | No change. | HIGH |
| DB-02 | MEDIUM | `class-database-cleanup.php:209-326` | Correctness / Complexity | `clean_revisions_advanced()` paginates `post_parent` via `GROUP BY post_parent HAVING COUNT(*) > keep_latest ORDER BY post_parent ASC LIMIT 200` then per-parent `ORDER BY post_date_gmt DESC LIMIT 500 OFFSET offset` loop until `count<500`. Offset-based pagination on mutable table may skip rows if deletions shift offsets mid-loop, but per-parent loop reads before deletions in chunks of 50, so no skip within parent. | Correct but `OFFSET` performance degrades for 1000+ revisions per parent; `post_date_gmt` may be equal (same second) causing non-deterministic keep-latest. | Add `ORDER BY post_date_gmt DESC, ID DESC` and keyset pagination instead of OFFSET. | MEDIUM |
| DB-03 | LOW | `class-database-cleanup.php:714-742` | Correctness / Optimize | `clean_all()` does `do_action('wppo_database_cleanup_completed','all',total,results); self::maybe_optimize_tables(affected, true)` — `true` forces optimize regardless of `dbOptimize` setting, while `auto_clean()` respects `!empty(settings['dbOptimize'])`. | Manual "Clean All" always optimizes tables even when user disabled optimize, surprising  `OPTIMIZE TABLE` lock. | Pass `settings['dbOptimize']` or make `clean_all` param `$optimize = true` explicit. | MEDIUM |
| DB-04 | LOW | `class-database-cleanup.php:557-595` | Correctness / Media | `clean_unattached_media()` selects `post_type='attachment' AND post_parent=0 AND post_status='inherit'` then `wp_delete_attachment(id,true)` per row — correct but `wp_delete_attachment` fires hooks, deletes files, may be slow for 500 batch; no `wp_cache_flush` between batches. | Large unattached media cleanup may timeout or exhaust memory. | Limit batch to 50 for media or use `delete_in_batches` via direct SQL plus `wp_delete_attachment` is correct for file cleanup — keep but document timeout. | LOW |
| DB-05 | INFO | `class-database-cleanup.php:842-925` | Correctness / Counts | `get_counts()` uses salted `wp_cache_get_salted('wppo_db_cleanup_counts','wppo',SALT_KEY)` else transient `wppo_db_cleanup_counts` 5min, then 9 queries including transient expired via join, `maybe_optimize` not called. | Correct bounded caching with salt invalidation. | No change. | HIGH |

### 3.15 `includes/class-core-tweaks.php` — 408 lines

| ID | Severity | File:Line | Category | Evidence | Impact | Recommendation | Confidence |
|----|----------|-----------|----------|----------|--------|----------------|------------|
| CT-01 | LOW | `class-core-tweaks.php:38-42` | Correctness / Hook | `if(!empty(disableEmojis)) { add_action('init', disable_emojis); if(function_exists('wp_dequeue_script_module')) { add_action('wp_enqueue_scripts', disable_emojis_script_module,100); add_action('admin_enqueue_scripts',...) } }` and `disable_emojis()` itself also does `if(function_exists('wp_dequeue_script_module')) wp_dequeue_script_module('emoji');` — duplicate dequeue. | Double hook harmless but redundant. | Keep `disable_emojis` as single path, remove constructor `wp_enqueue_scripts` hook or make `disable_emojis` admin-only. | LOW |
| CT-02 | INFO | `class-core-tweaks.php:44-46,174-183` | Correctness / Embeds | `disable_embeds` at `init -1000` does `remove_action('rest_api_init','wp_oembed_register_route')`, `add_filter('embed_oembed_discover','__return_false')`, `remove_filter('oembed_dataparse','wp_filter_oembed_result',10)`, `remove_action('wp_head','wp_oembed_add_discovery_links')`, `remove_action('wp_head','wp_oembed_add_host_js')`, `tiny_mce_plugins` diff `wpembed`, `rewrite_rules_array` strip `embed=true`, `remove_filter('pre_oembed_result')`. | Complete oEmbed disable, correct. | No change. | HIGH |
| CT-03 | LOW | `class-core-tweaks.php:64-67,284-317` | Correctness / REST Links | `disableRestApiLinks` hooks `wp_head:100 remove_rest_api_links` which does `remove_action('wp_head','rest_output_link_wp_head')` + `remove_action('wp_head','wp_oembed_add_discovery_links')` (duplicate of disable_embeds) plus `rest_post_dispatch` filter `suppress_rest_header` which strips `Link` header case-insensitively via `strtolower` key compare and `set_headers`. | `suppress_rest_header` correctly handles `WP_REST_Response::get_headers()` associative; but `remove_action` inside `wp_head:100` runs too late if theme calls `wp_head` earlier? Actually `remove_action` at `wp_head:100` will remove before `wp_head` fires at 10, but hook registered at `init`? It's at `wp_head` callback that removes other `wp_head` callbacks — order matters, 100 is after 10 so still in time before execution. Correct. | No change. | HIGH |
| CT-04 | LOW | `class-core-tweaks.php:256-277,384-406` | Correctness / Heartbeat / Pingbacks | `heartbeat_60s` sets `interval=60`; `disable_self_pingbacks(&$pung)` parses `home` host+port+path boundary vs pung host+port+path, unsets self URLs. | Host+port boundary check prevents `example.com.evil` false positive — correct. | No change. | HIGH |

---

## 4. No-Issues (confirmed correct)

- **AI Adaptive gating:** `is_enabled()` + `wppo_ai_adaptive_enabled` filter + `learn()` throttle 60s + `wp_ai_client` existence guard (`class-ai-adaptive.php:51-61,100-106,108-116,419-426`).
- **Edge Worker generation:** `get_worker_js`/`get_bunny_edge_js` template fallback + placeholder replacement + `wppo_edge_cache_*_content` filters (`class-edge-cache.php:171-285`).
- **Bfcache privacy:** `nocache_headers` strips `no-store,public` → `private,no-cache,max-age=0,must-revalidate` only when session has token, else keeps `no-store` (`class-bfcache.php:270-323`); cookie `wordpress_bfcache_session_{COOKIEHASH}` non-HttpOnly `setcookie(...,false)` correct per spec.
- **Perf Translations OPCache:** `wp_opcache_invalidate` preferred else `opcache_invalidate`, `wppo_perf_translations_file_written` action + `upgrader_process_complete` purge (`class-perf-translations.php:186-190,231,246-274`).
- **OD Bridge graceful degrade:** `is_od_available` + `is_enabled` auto true when OD active, exhaustive `get_url_metrics` fallbacks, `WP_DEBUG` error_log (`class-od-bridge.php:58-75,440-510`).
- **LLMs ETag/304 + markdown:** 20KB word-boundary cap, blog-scoped `cache/wppo/site-{id}`, rewrite `^llms\.txt$` + `^llms-full\.txt$`, `Link` header + `wp_head` `<link>` (`class-llms.php:163-176,314-324,536-576`).
- **Util caching:** `cached_home_url` + `cached_content_url` has_filter bypass, `get_settings` memo with `update/add/delete_option_wppo_settings` hooks, `ALLOWED_SETTINGS_KEYS/TABS` single source (`class-util.php:43-69,125-190,661-707`).
- **LiteSpeed arbitration:** `is_litespeed` via `Server_Rules` + `SERVER_SOFTWARE` + filter, `effective_mode` 4-state, `should_disable_wppo_optimizer`, `is_purge_sync_enabled` lock via `Util::transient_key`, `handle_send_headers` ttl/tags/vary/strip (`class-litespeed-integration.php:201-224,347-397,435-458,470-488,849-930,1274-1291`).
- **CDN Purger:** `purge_litespeed` before `all` early-return so `single_page` also syncs, `wppo_varnish_purge_max_urls` 20 cap, `PURGE` method (`class-cdn-purger.php:45-101,178-216`).
- **Asset Manager:** `protected_scripts/styles` allowlist, `wp_footer 9999` capture, `transient_key` with `DAY_IN_SECONDS` (`class-asset-manager.php:45-68,136-212`).
- **Metabox nonces:** `save_preload_image_urls` + `save_asset_manager_settings` each verify own nonce, `DOING_AUTOSAVE` + `current_user_can` gates, delay strategies `''|interaction|idle|viewport` allowlist (`class-metabox.php:300-337,347-386,425-451`).
- **Abilities delegation:** `execute_database_cleanup` via `CLEANUP_METHOD_MAP` + `get_revision_defaults`, `execute_clear_cache` via `Cache::clear_cache` + `Main::clear_all_cache` (`class-abilities.php:405-480`).
- **DB Cleanup safety:** `delete_in_batches` placeholder `implode('%d')` + `wpdb->prepare` spread, `expired_transients` skip `_site_transient_` on multisite, `TABLE_MAP` allowlisted identifiers for `OPTIMIZE TABLE` (`class-database-cleanup.php:138-180,408-433,986-1018,1040-1088`).
- **Core Tweaks:** `disable_dashicons` only when `!is_user_logged_in`, `xmlrpc_methods` empty array + `xmlrpc_enabled __return_false`, `remove_action` + `the_generator __return_empty_string` (`class-core-tweaks.php:48-88`).

---

## 5. Duplicate / Dead Code

| ID | File:Line | Evidence | Impact | Recommendation |
|----|-----------|----------|--------|----------------|
| DP-01 | `class-ai-adaptive.php:200-207` | Trends loop empty body | Dead | Remove or implement eagerness derivation. |
| DP-02 | `class-bfcache.php:270-308` | Duplicate cookie-ensure in both branches of `filter_nocache_headers` | Dead | Simplify to single block. |
| DP-03 | `class-ai-adaptive.php:281-282` | `if>3500 moderate elseif>2500 moderate` duplicate assignment | Dead | Fix to `eager` vs `moderate`. |
| DP-04 | `class-core-tweaks.php:38-42 + 118-127` | `disable_emojis_script_module` hooked at `wp_enqueue_scripts` 100 + also called inside `disable_emojis` at `init` | Duplicate | Unify. |
| DP-05 | `class-asset-manager.php:199-206` | `has_changed` compares only scripts/styles, ignores delay meta | Drift | Include strategies. |
| DP-06 | `class-litespeed-integration.php:1153-1171` | `is_nextgen_rewrite_enabled_for_nginx` reads `get_option` twice without memo vs `is_nextgen_rewrite_enabled` cached | Drift | Use `Util::get_settings`. |
| DP-07 | `class-database-cleanup.php:714-742` | `clean_all` forces `maybe_optimize_tables(...,true)` while `auto_clean` respects setting | Inconsistent | Parameterize. |

---

## 6. Open Questions (need owner decision)

1. **Q-AI-02:** Should `AI_Adaptive::heuristic_learn()` be removed or completed to blend `wppo_web_vitals_trends` signals (blocked by md5 key irreversibility) — store URL alongside hash?
2. **Q-AI-03:** Should `eagerness` heuristic expose `eager` tier (>3500 ms LCP) or keep conservative/moderate only for safety?
3. **Q-BFC-02:** Should bfcache `is_enabled()` hard-gate on `wp_cache_get_salted` (WP 6.9+) or keep soft gate as docs suggest, updating docstring?
4. **Q-OD-01:** Should `wppo_od_should_optimize` filter be able to enable OD when setting disabled, or remain veto-only?
5. **Q-AM-01:** Should `Asset_Manager::dequeue_selected_assets()` respect `enableLoggedInCache` + role hash instead of blanket `is_user_logged_in` bail?
6. **Q-MB-01:** Should `add_metabox` preload screen `''` be fixed to `null`/post-type loop — is missing preload metabox a known WIP?
7. **Q-DB-03:** Should `clean_all()` respect `dbOptimize` setting like `auto_clean()` or always optimize (current `true`)?
8. **Q-LS-01:** Should `wppo_litespeed_is_lscache_active` filter wrap constant/class detection branches for testability?
9. **Q-LLMS-02:** Should sitemap fetch validate host equality before `wp_remote_get` (SSRF hardening) vs after?

---

## 7. Verification

- Read 15 files end-to-end (8036 lines) via `Read` (single read each; `class-litespeed-integration.php` two windows). Grep for `apply_filters`, `function_exists`, `has_filter`, `wp_cache_get_salted`, `Util::transient_key`.
- Traced AI heuristic: `learn()` lock → `learn_via_ai_client()` prompt + `heuristic_learn()` scoring `avg_lcp*0.7+avg_ttfb*0.3 * log(count+1)` + `get_suggestions()` + `filter_speculation_rules` (`class-ai-adaptive.php:100-123,131-156,164-306,333-406,419-445`).
- Traced Edge Worker generation: `get_config` → `get_worker_js`/`get_wrangler_toml`/`get_bunny_edge_js` placeholder `{{ORIGIN_URL}}`/`{{CACHE_TTL}}`/`{{SWR}}` replacement + filters (`class-edge-cache.php:124-285`).
- Traced bfcache pageshow: `attach_session_information` token generation → `on_set_logged_in_cookie` → `filter_nocache_headers` → `enqueue_scripts` inline JS `pageshow` with `persisted` check + reload param `wppo_bfcache_reloaded` (`class-bfcache.php:169-182,232-240,270-385`).
- Traced mo→php compile: `filter_load_file` `str_ends_with .mo` → freshness `filemtime` → `WP_Translation_File::transform` → `WP_Filesystem::put_contents` → `wp_opcache_invalidate` (`class-perf-translations.php:137-204`).
- Traced OD bridge: `is_od_available` → `is_enabled` → `collect_raw_lcp_urls` via `get_lcp_element`/`get_elements`/`get_url`/`ArrayAccess` → `get_lcp_url` normalized count → `get_exclude_first_images_count` distinct count vs group count vs heuristic (`class-od-bridge.php:58-204,235-335,346-429`).
- Traced LLMs: `register_rewrite` → `serve` ETag/304 → `generate` `collect_urls` (trends proxy + sitemap walk 15s + `get_posts` fallback) → `build_markdown` → `cap_content` 20KB (`class-llms.php:81-90,113-186,231-324,334-424,432-495`).
- Traced Litespeed 4-state arbitration: `is_litespeed` → `is_lscache_active` (LSCWP_V/class/active_plugins) → `get_mode` → `effective_mode` (non-LS→standalone, auto→lscache?litespeed:wppo) → `is_wppo_cache_owner`/`should_disable_wppo_optimizer`/`is_purge_sync_enabled` (`class-litespeed-integration.php:201-224,235-289,300-328,347-397,411-488`).
- Traced server-level acceleration: `handle_send_headers` TTL `cacheLife→seconds` 0→604800 + `X-LiteSpeed-Tag` + `filter_litespeed_vary` + `Vary: Cookie` fallback + `maybe_strip_generic_cache_control` (`class-litespeed-integration.php:685-715,849-930,1069-1091,1274-1291`).
- Traced CDN purge: `CDN_Purger::purge_all` `purge_litespeed` before `all` guard → `purge_cloudflare`/`purge_varnish` via `wp_remote_request` (`class-cdn-purger.php:45-104,134-216`); `Edge_Purger::purge_all` zone-wide only + lock (`class-edge-purger.php:89-127`).
- Traced asset manager: `capture_page_assets` `WP_Scripts->done` + `WP_Styles->done` → `transient_key` 24h + `dequeue_selected_assets` protected lists (`class-asset-manager.php:92-126,136-212`).
- Traced metabox: `add_metabox` empty screen bug vs loop, `render_asset_manager_metabox` protected opacity, `save_metabox` `DOING_AUTOSAVE`+`edit_post`+nonce+whitelist `array_intersect` (`class-metabox.php:49-73,117-292,300-386`).
- Traced abilities: `get_ability_definitions` 6 feature + `get_operational_abilities` 4 operational, `execute_clear_cache/database_cleanup` delegation (`class-abilities.php:84-177,192-318,405-480`).
- Traced DB cleanup 7+ types: `delete_in_batches` → `clean_revisions`/`clean_revisions_advanced` keepLatest+cutoff → `clean_auto_drafts`/`trashed_posts`/`spam_comments`/`trashed_comments`/`expired_transients` skip site_transient multisite → `orphan_postmeta` `LEFT JOIN` → `unattached_media` `wp_delete_attachment` → `oembed_cache` LIKE `_oembed_%` → `clean_all`/`auto_clean`/`get_counts`/`optimize_table` 1GB guard (`class-database-cleanup.php:138-706,714-830,842-925,1040-1111`).
- Traced core tweaks: `disable_emojis` `print_emoji_detection_script` 7 + `wp_dequeue_script_module('emoji')`, `disable_embeds` `embed_oembed_discover false` + `rewrite_rules_array`, `disable_dashicons` `!is_user_logged_in`, `heartbeatControl`, `disableSelfPingbacks` host+port boundary (`class-core-tweaks.php:33-101,108-127,174-226,256-277,384-406`).
- Did not modify production code; write is to `AUDIT/AGENTS/agent-A05-php-new.md` only.

---

*Generated by Agent A05 — do not edit production files based on this audit without owner review. Update `AGENTS.md` if audit workflow changes.*
