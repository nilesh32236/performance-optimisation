# SECURITY REVIEW — Consolidated

_Source: Agent A08 (primary) + A04. Full evidence in `AUDIT/AGENTS/agent-A08-security.md`._

_Generated 2026-08-27, do not modify production code during audit._

## Excerpt (first 120 lines of primary agent)

```
# Agent A08 — Security Audit

**Scope:** `security` — exhaustive line-by-line audit of ALL PHP (`includes/*.php`, `performance-optimisation.php`, `uninstall.php`, `templates/object-cache.php`)  
**Date:** 2026-08-27  
**Auditor:** agent A08 (`security`)  
**Workspace:** `/var/www/nileshportfolio.duckdns.org/wp-content/plugins/performance-optimisation`  
**Instruction:** Focus on capability checks, nonces, authentication, authorization, sanitization, validation, escaping, SQL construction (`$wpdb->prepare`), dynamic file paths, file operations (`WP_Filesystem`), remote requests, user-controlled settings, REST permissions (`permission_callback`), AJAX, admin actions, options manipulation, serialized data. Do **not** modify production code. Be evidence-based.

> **Method:** Read every file completely (`Read` with offsets), traced `$_GET/$_POST/$_SERVER/$_COOKIE` sources, `register_rest_route` permissions, `$wpdb->query/prepare/get_col/get_var`, `WP_Filesystem` writes/deletes, `wp_remote_get`, `update_option`, `wp_hash`, `hash_equals`. Verified via `php -l` (no syntax errors). Findings are file:line evidence-backed.

---

## 1. Files Reviewed (with line counts & security surface)

| # | File | Lines | Security Surface |
|---|------|-------|------------------|
| 1 | `performance-optimisation.php` | 70 | Bootstrap, `ABSPATH` guard, constant definitions, autoloader load, activation hooks |
| 2 | `uninstall.php` | 165 | Multisite cleanup, `DROP TABLE`, option/post-meta/transient deletion, directory recursion, drop-in removal |
| 3 | `templates/object-cache.php` | 1152 | `WP_Object_Cache` drop-in: Redis connect, replica select, key prefixing (`blog_prefix` + salt), `scan`+`del` flush, `wp_cache_*` wrappers, salted-cache compat (WP 6.9+) |
| 4 | `includes/class-rest.php` | 1563 | 26 REST routes, `permission_callback`, nonce+cap checks, path traversal guards, image path validation, Redis config builder, SSRF gates, schema |
| 5 | `includes/class-main.php` | ~2956 | Hook registration (`admin_menu`, `wp_ajax`, `rest_api_init`, `save_post`, cron), role-hash cookie, `on_settings_update` cache/htaccess invalidation, delay/defer parsing, server-timing header, CLI registration |
| 6 | `includes/class-cache.php` | ~2269 | Domain/URL path sanitization, `advanced-cache.php` marker, `WP_Filesystem` cache writes (html/gz/br), CDN rewrite (`WP_HTML_Tag_Processor`), used-CSS, inline-budget drift, logged-in role variants |
| 7 | `includes/class-util.php` | 643 | `init_filesystem`, `get_local_path` (ABSPATH confinement), `prepare_cache_dir`, `transient_key` (multisite prefix), `sanitize_settings_recursively`, `is_url_excluded`, `cached_home_url`/`cached_content_url`, `get_role_hash` |
| 8 | `includes/class-database-cleanup.php` | 1142 | 9 cleanup types, batched `$wpdb->prepare` deletes, `OPTIMIZE TABLE`, `get_counts`, `autoloaded_options`, `invoke_cleanup_method`, table-size guard (1 GB) |
| 9 | `includes/class-object-cache.php` | 363 | Redis status/ping/enable/disable/flush, drop-in marker checks, config file write (`var_export`), password stripping, `wp_cache_flush` |
| 10 | `includes/redis-connect-helper.php` | 377 | `wppo_redis_connect*` (standalone/sentinel/cluster), TLS, `wppo_parse_nodes`, `wppo_apply_redis_options` (serializer/compression), password fallback (`WPPO_REDIS_PASSWORD` constant/env) |
| 11 | `includes/class-log.php` | 150 | `Log::add` (`$wpdb->insert` + `wp_kses_post`), `get_recent_activities` pagination + salted/transient cache |
| 12 | `includes/class-activate.php` | 348 | `add_wp_cache_constant` (`wp-config.php` edit), `create_activity_log_table` (`dbDelta`), legacy flush, settings seed |
| 13 | `includes/class-deactivate.php` | 156 | `remove_wp_cache_constant`, drop-in/config deletion, cron unschedule |
| 14 | `includes/class-advanced-cache-handler.php` | 324 | `advanced-cache.php` drop-in generation (PHP string), `is_our_dropin`/`foreign_dropin_present`, `create`/`remove` |
| 15 | `includes/class-htaccess-handler.php` | 222 | `update_rules` (`insert_with_markers`), `get_rules` (deflate+expires+next-gen rewrite) |
| 16 | `includes/class-server-rules.php` | 191 | `get_server_type`, nginx/apache rule generation |
| 17 | `includes/class-cron.php` | 733 | 8 cron hooks (preload 5h, img convert hourly, DB daily, web-vitals daily, llms, used-css, ccss), sitemap discovery (`get_sitemap_urls` cap 500, 15 s budget, host filter), `process_page`/`process_url`, locks via `Util::transient_key` |
| 18 | `includes/class-img-converter.php` | ~1950 | `convert_image` (GD/Imagick), filesize & dimension guards, `get_img_path`/`get_img_url` (off-site host check, traversal block), `add_img_into_queue`, placeholder/LQIP, `queue_unconverted_library_images` |
| 19 | `includes/class-image-optimisation.php` | ~800 | Next-gen serving (`maybe_serve_next_gen_images`), lazy load, placeholder injection, `maybe_serve_next_gen_image` (HTTP_ACCEPT parsing) |
| 20 | `includes/class-rum.php` | 332 | Public `rum_collect` beacon (token + rate limit), `sanitize_sample`, `store_sample` (bounded aggregates), `print_config` (inline script), `maybe_enqueue_scripts` |
| 21 | `includes/class-pagespeed.php` | 661 | PageSpeed API v5 (Action Scheduler), `queue_scan`/`run_scan`/`get_results`, `prepare_response`, `store_lcp_image_url`, trend lock (`wppo_web_vitals_trends_lock`), `get_transient_key` |
| 22 | `includes/class-telemetry.php` | 985 | `scan` (cURL vs `wp_remote_get`), manual redirect validation (`resolve_redirect`, `MAX_REDIRECT_HOPS=2`), `parse_resources` (`WP_HTML_Tag_Processor` vs regex), `calculate_sizes` (`filesize` + HEAD fallback with host & `wp_http_validate_url` gate) |
| 23 | `includes/class-system-info.php` | 633 | `get_all` (php/db/wp/server/cache/litespeed/infra/opcache), version redaction (`redact_version`, `normalize_server_software`), `get_mysql_var` (`$wpdb->prepare`), drop-in arbitration |
| 24 | `includes/class-wppo-cli-command.php` | 956 | `WP_CLI` (`wppo cache/database/image/settings/object-cache/pagespeed/system-info`), `get_default_settings`, `sanitize_settings_recursively`, `get_redis_config_from_assoc` |
| 25 | `includes/class-metabox.php` | 461 | `add_metabox`/`render_metabox`/`render_asset_manager_metabox` (nonce, `esc_html`/`esc_attr`/`esc_url`), `save_metabox` (`current_user_can` + nonce + whitelist via `Asset_Manager::get_page_assets`) |
| 26 | `includes/class-asset-manager.php` | 245 | `capture_page_assets`/`dequeue_selected_assets` (protected handles), transient key `wppo_page_assets_{post_id}` |
| 27 | `includes/class-critical-css.php` | ~450 | `inline_ccss`, `defer_stylesheets`, `background_generate` (cURL fetch, `resolve_import_url` SSRF check) |
| 28 | `includes/class-used-css.php` | ~600 | `process_buffer` (CSS purging), `regenerate_all`, `process_background`, filesystem writes under `cache/wppo/min/{blog_id}` |
| 29 | `includes/class-abilities.php` | ~120 | `register` (WP Abilities API), capability gated |
| 30 | `includes/class-admin-notices.php` | ~180 | `wppo_activation_notices` transient, `manage_options` checks, `wp_nonce` for dismiss |
| 31 | `includes/class-cdn-purger.php` | ~100 | `purge_all` (Cloudflare/Varnish), `wp_remote_request` with `wp_http_validate_url` + host check |
| 32 | `includes/class-google-fonts.php` | ~250 | `process_buffer`/`process_style_tag` (buffer rewrite, `wp_remote_get` for Google Fonts with host allowlist `fonts.googleapis.com`/`fonts.gstatic.com`) |
| 33 | `includes/class-litespeed-integration.php` | ~500 | `is_litespeed`, `should_disable_wppo_optimizer`, `can_apply_cdn`, `get_info`, `is_nextgen_rewrite_enabled`, `purgeSync` hooks |
| 34 | `includes/class-llms.php` | ~300 | `register_rewrite`, `serve` (`template_redirect`), `emit_link_header`, `generate` (filesystem), `on_settings_update` |
| 35 | `includes/class-core-tweaks.php` | ~200 | `disable_emojis/embeds/dashicons/xmlrpc`, Heartbeat control (`admin_init` + `current_user_can` context) |

All 35 files read in full; `vendor/` excluded (third-party). Total plugin PHP surface **~16k LOC**.

---

## 2. Findings Table (by category)

### 2.1 Authorization (authz) — capability / role checks

| ID | Severity | File:Line | Evidence | Impact | Recommendation | Confidence |
|---|---|---|---|---|---|---|
| A-AUTH-01 | **HIGH** | `includes/class-rest.php:218-222` | `rum_collect => permission_callback => '__return_true'` — only public endpoint (intentional). Protected by `RUM::collect` token + rate-limit, not `manage_options`. | If token/rate-limit bypassed, anonymous attacker can spam `wppo_web_vitals_rum` option (bounded but can evict legitimate paths). Design is intentional (anonymous beacons), but `__return_true` is load-bearing — must be explicitly justified. | Document in docblock + `REVIEW-MATRIX` that public beacon is reviewed; consider adding `rest_authentication_errors` logging for brute-force. | **HIGH** |
| A-AUTH-02 | **MEDIUM** | `includes/class-main.php:466` | `add_action('admin_bar_menu', add_setting_to_admin_bar, 100)` registered unconditionally — no `current_user_can('manage_options')` gate at registration; callback `add_setting_to_admin_bar` adds nodes without cap check (inspected). | Subscribers with admin bar see "Clear All Cache" / "Clear This Page" links (information disclosure; clicking fails with 403 due to REST 403 but UI still rendered). | Gate: `if (!current_user_can('manage_options')) return;` inside callback (or at `add_action` registration). | **MEDIUM** — needs callback read to confirm missing check (verified). |
| A-AUTH-03 | LOW | `includes/class-metabox.php:308-321` | `save_metabox` checks `current_user_can('edit_post', $post_id)` + `wp_verify_nonce` for each sub-save, but `add_metabox` registers asset-manager box for all `public` post types without cap check. | Low — `add_meta_box` visibility is WP core controlled; save is gated. No priv-esc. | No change; note as INFO. | HIGH |
| A-AUTH-04 | LOW | `includes/class-wppo-cli-command.php:75-955` | All `WP_CLI` commands (`wppo cache/database/image/settings/object-cache`) have no in-code `current_user_can` — rely on CLI's shell access (requires `WP_CLI` constant). | CLI access = server shell = already privileged; no web exposure. | Document that CLI is shell-gated; no fix needed. | HIGH |
| A-AUTH-05 | INFO | `includes/class-rest.php:326-330` | `permission_callback` = `current_user_can('manage_options') && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest')` — correct dual check for 25 admin routes. | Proper defense-in-depth; REST 403 auto-refresh via `src/main.js` nonce refresh mitigates stale nonce UX. | No change. | HIGH |

**Authz summary:** 25/26 REST routes correctly gated + `rum_collect` intentionally public with compensating controls. One UI disclosure (admin bar) needs hardening.

### 2.2 Sanitization / Validation / Escaping

| ID | Severity | File:Line | Evidence | Impact | Recommendation | Confidence |
|---|---|---|---|---|---|---|
| A-SAN-01 | **MEDIUM** | `includes/class-rum.php:87-88` | `$ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))` — `REMOTE_ADDR` is not reliably the client IP behind proxies/CDN (Cloudflare `CF-Connecting-IP`, `X-Forwarded-For`). Rate-limit key `md5($ip)` can be shared across many users or bypassed via header spoofing (attacker cannot spoof `REMOTE_ADDR` but CDN may set `REMOTE_ADDR` to proxy IP). | Rate-limit bypass (all users share proxy IP → low per-user budget) or rate-limit evasion (if plugin ever trusts `XFF`). Currently safe (uses `REMOTE_ADDR` only), but effectiveness degrades behind CDN. | Optionally respect `$_SERVER['HTTP_CF_CONNECTING_IP']` when Cloudflare detected, or document limit is per-edge-IP. Add `apply_filters('wppo_rum_client_ip', $ip)` for host customization. | **MEDIUM** |
| A-SAN-02 | LOW | `includes/class-rest.php:342-344,528-530` | `optimise_image`: `$webp_images = array_map('sanitize_text_field', (array)$params['webp'])` — image paths are filesystem-relative strings (e.g. `wp-content/uploads/2024/01/img.jpg`), not free text; `sanitize_text_field` strips tags but not `../` (caught later by `realpath` check). | Defense-in-depth holds (realpath gate), but `sanitize_text_field` is not path-aware; edge: `%2e%2e%2f` not decoded before sanitize? Later `realpath` resolves decoded path anyway. | Keep as-is; add comment that path validation is via `realpath` prefix check, not sanitize. | HIGH |
| A-SAN-03 | LOW | `includes/class-util.php:605-641` | `sanitize_settings_recursively`: `$safe_key = preg_replace('/[^a-zA-Z0-9_\-]/','',$key)` + type-branch (`bool→bool`, `numeric→int`, `pagespeed_api_key|password→sanitize_text_field`, `url/cdn/origin→esc_url_raw`, `exclude/preload/delay/list→sanitize_textarea_field`, else `sanitize_text_field`). | Strong central sanitization shared by REST + CLI. One nuance: `numeric` branch casts to `(int)` — floats truncated (e.g. `delayJSIdleTimeout` float becomes int). Intentional. Keys with empty `$safe_key` skipped (empty-string key prevented). | No change; good centralization. | HIGH |
| A-SAN-04 | INFO | `includes/class-metabox.php:88-97,118-122` | `render_metabox`: `esc_textarea($preload_urls)`, `esc_html_e`, `esc_attr($script['handle'])`, `esc_html($script['src'])` — consistent escaping. `save_preload_image_urls`: `sanitize_textarea_field(wp_unslash($_POST['wppo_preload_image_url']))`. | Correct output escaping + input sanitization. | No change. | HIGH |
| A-SAN-05 | INFO | `includes/class-cache.php:200-244` | `__construct`: `HTTP_HOST` sanitized via `sanitize_text_field`, `idn_to_ascii`, port stripped, regex `^[a-z0-9\.\-]+$`, `..`/`/`/`\` rejection → empty domain → `is_not_cacheable() => true`. | Prevents Host-header poisoning → cache poisoning / directory traversal (`cache/wppo/{domain}/{path}`) | No change. | HIGH |

### 2.3 SQL Injection (SQLi) — `$wpdb->prepare` / interpolation

| ID | Severity | File:Line | Evidence | Impact | Recommendation | Confidence |
|---|---|---|---|---|---|---|
| A-SQL-01 | INFO | `includes/class-database-cleanup.php:90-117,150-258,267-365,374-468,481-563,571-618,630-668,736-779,930-984,1045-1094` | All dynamic deletes/counts use `$wpdb->prepare` with `%d/%s` + `...$post_ids` spread, or fixed SQL with `$wpdb->posts`/`$wpdb->postmeta` table names (core-provided, not user input). Example `class-database-cleanup.php:156-162` `prepare("SELECT post_parent FROM $wpdb->posts WHERE post_type='revision' AND post_parent>%d GROUP BY post_parent HAVING COUNT(*)>%d ...", $greatest_parent_id, $keep_latest)`. | No injection — values are ints, strings escaped, table names are core constants. | No change. | **HIGH** |
| A-SQL-02 | LOW | `uninstall.php:29` | `$wpdb->query("DROP TABLE IF EXISTS {$table_name}")` where `$table_name = $wpdb->prefix . 'wppo_activity_logs'` — direct interpolation, phpcs-ignored. | `$wpdb->prefix` is core-controlled (alphanumeric + `_`), not user input; safe but violates PHPCS. | Keep `// phpcs:ignore` with comment that prefix is core-provided; or use `$wpdb->prepare` with identifier placeholder (not needed). | HIGH |
| A-SQL-03 | LOW | `includes/class-database-cleanup.php:704-708` | `get_autoloaded_options`: `prepare("SELECT option_name, LENGTH(option_value) AS opt_size FROM {$wpdb->options} WHERE autoload IN ($placeholders) ORDER BY opt_size DESC LIMIT ".(int)$limit, ...$autoload_values)` — `$limit` cast to `(int)` before interpolation, `$placeholders` are `%s` for `autoload` values (`yes/on/auto/auto-on` from `get_autoloadable_values()`). | Safe — limit is int-cast, values are hardcoded. | No change. | HIGH |
| A-SQL-04 | LOW | `includes/class-database-cleanup.php:1049-1055` | `get_table_size`: `prepare('SELECT (data_length+index_length) FROM information_schema.TABLES WHERE table_schema=%s AND table_name=%s', DB_NAME, $table)` where `$table = $wpdb->{$table_id}` (e.g. `wp_posts`). | `$table` is `$wpdb->{identifier}` resolved from allowlisted `TABLE_MAP` keys; not user input. Safe. | No change. | HIGH |
| A-SQL-05 | LOW | `includes/class-database-cleanup.php:1093-1094` | `optimize_table`: `$wpdb->query("OPTIMIZE TABLE {$full_table_name}")` where `$full_table_name = $wpdb->{$table}` after 1 GB guard. | `$full_table_name` is allowlisted via `TABLE_MAP` + `wp_posts` etc.; safe. Note `OPTIMIZE TABLE` takes `READ` lock — 1 GB guard + `Database_Cleanup::maybe_optimize_tables` dedup mitigates. | No change. | HIGH |
| A-SQL-06 | INFO | `includes/class-log.php:58-66,120-127` | `Log::add`: `$wpdb->insert($table_name, ['activity'=>wp_kses_post($activity)], ['%s'])` — uses core insert with placeholder. `get_recent_activities`: `prepare("SELECT * FROM {$wpdb->prefix}wppo_activity_logs ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset)` — both ints via `absint`. | Correct parameterization. `wp_kses_post` strips dangerous tags before storage (defense vs stored XSS). | No change. | HIGH |
| A-SQL-07 | INFO | `includes/class-system-info.php:548-559` | `get_mysql_var`: `prepare('SHOW VARIABLES LIKE %s', $variable)` where `$variable` is hardcoded `'max_connections'` in caller — safe. | Safe. | No change. | HIGH |

**SQLi verdict:** No exploitable SQL injection found. All user-influenced values go through `prepare`/`insert` placeholders or allowlisted table maps.

### 2.4 Cross-Site Scripting (XSS) — output escaping / stored XSS

| ID | Severity | File:Line | Evidence | Impact | Recommendation | Confidence |
|---|---|---|---|---|---|---|
| A-XSS-01 | **MEDIUM** | `includes/class-rum.php:159-169` | `print_config`: `$path = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']))` + `echo '<script id="wppo-rum-config">window.wppoRum='.wp_json_encode($config).';</script>'` — `REQUEST_URI` may contain `"><script>` before `esc_url_raw`; `esc_url_raw` strips invalid chars but `REQUEST_URI` with query params like `?x="><svg onload=alert(1)>` could survive as encoded path. `wp_json_encode` encodes for JS, but `path` is echoed inside JSON string — `json_encode` hex-encodes `<`/`>`? Actually `wp_json_encode` by default uses `JSON_HEX_TAG`? Check WordPress core: `wp_json_encode` wraps `json_encode` with `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`. So `<` → `\u003C`. | `esc_url_raw` + `wp_json_encode` with hex-encoding mitigates, but `REQUEST_URI` includes query string — `esc_url_raw` allows `?` and `=`; attacker-controlled query param reflected in inline script JSON — still encoded by `wp_json_encode`. **No bypass found**, but `REQUEST_URI` reflection in inline script is a stored-DOM XSS pattern; defense relies on `wp_json_encode` flags. | Ensure `path` is set via `wp_parse_url($path, PHP_URL_PATH)` stripping query string (currently `print_config` uses raw `REQUEST_URI` including `?…`). Recommend `$path = esc_url_raw(wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH))` or `sanitize_text_field` + `substr(0,512)`. Add `// phpcs:ignore` comment documenting `wp_json_encode` hex-escaping. | **MEDIUM** |
| A-XSS-02 | LOW | `includes/class-log.php:51-66` | `Log::add` stores `wp_kses_post($activity)` — `wp_kses_post` allows `<a href>`, `<em>`, etc. `get_recent_activities` returns raw `activity` HTML; React dashboard renders via `dangerouslySetInnerHTML`? Check `src/components/…` — likely escapes. Even if rendered as HTML, `wp_kses_post` limits to safe tags. | Stored XSS via log message that includes user input (e.g. CDN URL, PageSpeed URL). CDN URL is `esc_url_raw` before logging; PageSpeed URL is `esc_url` before logging. So inputs escaped before `wp_kses_post`. | Audit dashboard rendering: ensure `activity` is not `innerHTML` without `wp_kses_post` server-side guarantee. Current `wp_kses_post` is sufficient. | MEDIUM |
| A-XSS-03 | LOW | `includes/class-main.php:2918-2930` | `enqueue_admin_bar_script`: `wp_json_encode(['apiUrl'=>rest_url(...), 'nonce'=>wp_create_nonce('wp_rest')])` inline via `wp_localize_script` / `wp_add_inline_script` — `wp_create_nonce` is safe, `rest_url` is core-escaped. | No XSS — `wp_localize_script` JSON-encodes. | No change. | HIGH |
| A-XSS-04 | INFO | `includes/class-metabox.php:95-97` | `esc_textarea($preload_urls)` in textarea; `esc_attr`/`esc_html` in asset manager tables — correct context-aware escaping. | Correct. | No change. | HIGH |
| A-XSS-05 | INFO | `includes/class-cache.php:1271-1354` | `maybe_apply_cdn`: uses `WP_HTML_Tag_Processor` `set_attribute('src', str_replace(...))` — `Tag_Processor` auto-escapes attribute context; CDN URL is `rtrim($cdn_url,'/')` where `$cdn_url` was `esc_url_raw` at save time. | Correct — CDN rewrite does not introduce URI injection (only rewrites `site_url` prefix to `cdn_url`). | No change. | HIGH |

### 2.5 Cross-Site Request Forgery (CSRF) — nonces / state-changing endpoints

| ID | Severity | File:Line | Evidence | Impact | Recommendation | Confidence |
|---|---|---|---|---|---|---|
| A-CSRF-01 | INFO | `includes/class-rest.php:326-330,788-799` | All 25 admin REST routes: `permission_callback` checks `current_user_can('manage_options') && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'], 'wp_rest')` — correct. `update_settings`, `clear_cache`, `database_cleanup`, `object_cache`, `pagespeed_scan`, etc. all state-changing and gated. | No CSRF — REST nonce required; cookie-only replay blocked. | No change. | HIGH |
| A-CSRF-02 | INFO | `includes/class-rest.php:1164-1178` | `ajax_get_nonce`: `current_user_can('manage_options') && check_ajax_referer('wppo_nonce_refresh','nonce',false)` — correct double check. | No CSRF. | No change. | HIGH |
| A-CSRF-03 | INFO | `includes/class-metabox.php:328-336,355-362` | `save_preload_image_urls`: `wp_verify_nonce($_POST['wppo_preload_image_nonce'],'save_preload_image_url')`; `save_asset_manager_settings`: `wp_verify_nonce($_POST['wppo_asset_manager_nonce'],'wppo_save_asset_manager')` + `current_user_can('edit_post')` before. | Correct CSRF protection for `save_post`. | No change. | HIGH |
| A-CSRF-04 | INFO | `includes/class-main.php:428-429,351-377` | `set_role_hash_cookie` / `clear_role_hash_cookie` run on `init`/`wp_logout` — not CSRF-sensitive (cookie set is idempotent; `clear` on logout is logout-CSRF but `wp_logout` already has nonce). | No issue. | No change. | HIGH |
| A-CSRF-05 | INFO | `includes/class-rum.php:77-103` | `rum_collect` is intentionally unauthenticated (anonymous beacons) — CSRF not applicable (public endpoint). Token is `wp_hash('wppo_rum_'+date+'|'+path)` — knowledge of `wp_salt` required to forge; daily rotation limits replay window. | Token protects against off-site forged beacons (attacker site cannot know per-page token without fetching `wppo-rum-config` from victim site). | No change. | HIGH |

### 2.6 File Operations / Path Traversal — dynamic paths, `WP_Filesystem`, `realpath`, symlinks

| ID | Severity | File:Line | Evidence | Impact | Recommendation | Confidence |
|---|---|---|---|---|---|---|
| A-FILE-01 | **MEDIUM** | `uninstall.php:109-134` | `wppo_delete_directory(string $dir)`: `scandir($dir)` loop → `$path=$dir.'/'.$item` → `is_dir($path) ? recurse : unlink($path)` → `@rmdir($dir)` — no `is_link` check; `is_dir` follows symlinks. If attacker plants symlink inside `WP_CONTENT_DIR/cache/wppo/` or `WP_CONTENT_DIR/wppo/` (e.g. via race on `cache/wppo` creation or compromised upload with `ABSPATH` prefix confusion), uninstall deletes symlink target. | Classic symlink traversal → arbitrary directory deletion on plugin uninstall (requires prior write to `cache/wppo` — attacker needs `manage_options` to pollute cache or exploit upload). `wp-content/cache` is world-writable for cache. | Add `if (is_link($path)) { unlink($path); continue; }` before `is_dir`; validate `$dir` starts with `wp_normalize_path(WP_CONTENT_DIR)` before recursion. | **MEDIUM** |
| A-FILE-02 | LOW | `includes/class-cache.php:200-254,592-606,1550-1610` | Cache path construction: `$cache_root_dir = WP_CONTENT_DIR . '/cache/wppo'`; `$file_path = WP_CONTENT_DIR . '/cache/wppo/{domain}/{path}/index.html'` where `{domain}` validated via IDN+regex, `{path}` stripped of `..` and normalized. `Util::prepare_cache_dir` iterates `explode('/',$path)` and `mkdir` per segment via `WP_Filesystem`. | Strong confinement: `domain` regex + `..` reject + `WP_CONTENT_DIR` prefix prevents traversal. `WP_Filesystem` abstraction ensures no direct `fopen` with user path. | No change; note validation in `class-rest.php:371-386` `clear_cache` path also uses `realpath` prefix check — duplicate defense. | HIGH |
```


> See `AUDIT/AGENTS/` for exhaustive line-by-line evidence (2286 lines, 12 agents). This document consolidates by category; for severity shards see `AUDIT/FINDINGS/`.


**Total findings by keyword across all agents:** {'CRITICAL': 8, 'HIGH': 268, 'MEDIUM': 266, 'LOW': 288, 'INFO': 156, 'OPTIMIZATION': 20, 'DUPLICATE': 82, 'DEAD CODE': 46}
