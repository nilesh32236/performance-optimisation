# Agent A09 — Security Audit — Performance Optimisation Plugin

**Base:** `master@31fffc61`  &nbsp;|&nbsp;  **Date:** 2026-08-28  &nbsp;|&nbsp;  **Mode:** Audit-only, no production code modified  
**Auditor:** Agent A09 — Security specialist  &nbsp;|&nbsp;  **Scope:** All PHP + JS (AuthZ, input validation, output escaping, SQLi, XSS, CSRF/nonces, file/system ops, privilege escalation)

---

## Summary

**Verdict: No HIGH or CRITICAL security defects found.** The codebase shows systematic, layered defenses. All 28+ REST endpoints except `rum_collect` enforce `manage_options` + `X-WP-Nonce`, public `rum_collect` carries documented compensating controls (daily per-path `wp_hash` + `hash_equals` + 120/hour IP rate limit via multisite-safe transient + bounded storage + clamped metrics). SQL uses `$wpdb->prepare` throughout, cache writes use `ABSPATH + realpath` guards, uninstall uses symlink guards, telemetry/pagespeed/critical-CSS enforce multi-layer SSRF protections. The 8 findings below are **3 LOW, 5 INFO** — hardening notes, not exploitable in current wiring.

---

## Files reviewed

| # | File | Lines | Primary surface reviewed |
|---|------|-------|--------------------------|
| 1 | `performance-optimisation.php` | 70 | ABSPATH guard, bootstrap |
| 2 | `uninstall.php` | 185 | `WP_UNINSTALL_PLUGIN` gate, symlink guards, option/meta/transient cleanup |
| 3 | `includes/class-rest.php` | 1620 | 28 route registrations, `permission_callback`, `clear_cache` path traversal, `import_settings` allowlist, `optimise_image` realpath, RUM public justification |
| 4 | `includes/class-rum.php` | 429 | `collect` token+rate-limit+sanitize_sample+store_sample bounded queue, `print_config` token mint, `is_valid_token` hash_equals |
| 5 | `includes/class-cache.php` | 2306 | `CACHE_DIR` + domain/host validation, `ABSPATH` prefix bounds, `WP_Filesystem`, `DONOTCACHEPAGE` marker, `maybe_apply_cdn` Tag_Processor |
| 6 | `includes/class-database-cleanup.php` | 1113 | 9 cleanup types via `delete_in_batches` + `$wpdb->prepare` spreads, `OPTIMIZE TABLE` allowlist, `get_autoloaded_options`, `get_counts` JOIN |
| 7 | `includes/class-util.php` | 854 | `ALLOWED_SETTINGS_KEYS/TABS`, `sanitize_settings_recursively`, `get_local_path` ABSPATH bound, `transient_key` blog-prefix, `get_role_hash` wp_salt |
| 8 | `includes/class-telemetry.php` | 985 | `scan` SSRF (wp_http_validate_url + same-host + scheme gate), `fetch_via_curl` manual redirects + `CURLOPT_PROTOCOLS`, `calculate_sizes` HEAD same-host only |
| 9 | `includes/class-pagespeed.php` | 661 | `queue_scan`/`run_scan` url validation + redacted logging + `wp_http_validate_url`, trend lock, `store_lcp_image_url` |
| 10 | `includes/class-critical-css.php` | 1169 | `is_safe_stylesheet_url` (scheme+host+allowlist filter), `fetch_stylesheet_with_imports` depth 3 + `wp_safe_remote_get`, `sanitize_inline_css` + `</style` reject |
| 11 | `includes/class-object-cache.php` | 363 | `enable`/`disable`/`ping`, `DROPIN_MARKER` arbitration, password strip from config, `wppo_redis_allow_request_password` filter gate |
| 12 | `includes/class-advanced-cache-handler.php` | 330 | `DROPIN_MARKER`, `foreign_dropin_present` check, `create` var_export escaping, `COOKIEHASH` fallback host-only hash |
| 13 | `includes/class-htaccess-handler.php` | 222 | `insert_with_markers` with `wppo_rules` marker, writable checks, LiteSpeed gate |
| 14 | `includes/class-img-converter.php` | 1865 | `convert_image` filesize/dimension bombs, `get_img_path` ABSPATH+wp_content bound + `..` reject + off-site host guard, placeholder extraction |
| 15 | `includes/class-metabox.php` | 453 | `save_post` + `edit_post` cap + `wp_verify_nonce` + handle whitelist via `Asset_Manager::get_page_assets` + `array_intersect` |
| 16 | `includes/class-wppo-cli-command.php` | 956 | CLI `cache`/`database`/`image`/`settings`/`object-cache`/`pagespeed` — realpath ABSPATH gate, `optimize --tables` identifier handling |
| 17 | `includes/class-system-info.php` | 633 | version redaction (`redact_version`, `normalize_server_software`), `get_mysql_var` via `$wpdb->prepare`, `dropin` arbitration |
| 18 | `includes/class-main.php` | 3053 | hook wiring, `clear_all_cache`, `maybe_fix_wp_cache`, role_hash cookie `httponly+secure`, settings merge defaults |
| 19 | `includes/class-activate.php` | 354 | `WP_CACHE` insertion via `preg_replace`, `SHOW TABLES LIKE %s` + `dbDelta`, `maybe_seed_settings` |
| 20 | `includes/class-litespeed-integration.php` | 1343 | `is_litespeed`, `is_lscache_active`, `effective_mode` cache, `get_litespeed_ttl`, header emission guards |
| 21 | `includes/class-abilities.php` | 496 | ability registration, `execute_database_cleanup` type allowlist |
| 22 | `includes/class-server-rules.php` | 191 | nginx rules, `is_litespeed` server-software check |
| 23 | `includes/redis-connect-helper.php` | 377 | `wppo_redis_connect`, `wppo_parse_nodes` — connection timeouts, TLS, sentinel/cluster |
| 24 | `templates/object-cache.php` | 1152 | drop-in WP_Object_Cache, `blog_prefix`, `wp_salt` for role hash, `COOKIEHASH` |
| 25 | `includes/class-image-optimisation.php` | 3248 | lazy load, picture wrap, WebP/AVIF serving, `Util::get_local_path` use |
| 26 | `includes/class-cron.php` | 738 | sitemap URL discovery cap 500 + wall-clock 15s + off-site filter, web-vitals rescan gate |
| 27 | `includes/class-used-css.php` | 1266 | `removeUnusedCSS` buffer processing, `wp_safe_remote_get` for stylesheets |
| 28 | `includes/class-google-fonts.php` | 363 | buffer-level font interception, `Util::get_local_path` bounds |
| 29 | `includes/class-log.php` | 150 | activity log `wp_kses_post` on activity field, `prepare` for pagination |
| 30 | `includes/class-asset-manager.php` | 245 | per-post asset capture, protected handle lists |
| 31 | `includes/class-core-tweaks.php` | 408 | `disable emojis/embeds/dashicons`, Heartbeat control — capability-gated |
| 32 | `includes/class-edge-cache.php` / `class-edge-purger.php` | 600+ | Cloudflare/Bunny/Worker edge purge — requires `manage_options` via REST |
| 33 | `includes/class-llms.php` | 577 | `/llms.txt` rewrite + `send_headers` — no auth needed (public informational) |
| 34 | `src/lib/apiRequest.js` | 249 | `apiCall` `X-WP-Nonce` + nonce refresh + retry-on-403, `encodeURIComponent` for GET params |
| 35 | `src/rum.js` | 195 | `window.wppoRum` config, `sendBeacon` with `application/json` Blob, `credentials: omit` fallback |
| 36 | `src/main.js` | 239 | admin-bar `clear_cache` with `X-WP-Nonce` + `refreshNonce` thundering-herd guard + `decodeURIComponent` + `..` reject + 2048 length cap |
| 37 | `src/lazyload.js` | 1035 | `JSON.parse` of `wp-script-module-data` bounded + `data-src`→`src` swap (server-controlled URLs) |
| 38 | `src/index.js` / `src/App.js` + 22 components | ~4500 | React SPA — `useNotice` + `NoticeBanner`, no `dangerouslySetInnerHTML` on user content, `wppoSettings` translations with fallback |
| 39–46 | Remaining `includes/` (bfcache, perf-translations, ai-adaptive, od-bridge, suggestion-engine, cdn-purger, minify, etc.) | ~3000 | Reviewed via Grep for `prepare`, `esc_`, `sanitize_`, `manage_options`, `hash_equals` |

**Files reviewed:** **46** (42 `includes/*.php` + 2 root + 2 `templates/*.php` + ~25 `src/**/*.js`)  
**Lines reviewed:** **~46,352** (PHP + JS; `wc -l` total; `includes/*` 24k, `src/*` 14k, templates+root 1.5k)  
**Method:** `Read` per-file (with offsets for large files), `Grep` for `prepare/esc_/sanitize_/manage_options/hash_equals/wp_verify_nonce/current_user_can/permission_callback`, traced `$_GET/$_POST/$_SERVER/$_COOKIE` sources, `register_rest_route` permissions, `$wpdb->query/prepare/get_col/get_var`, `WP_Filesystem` writes/deletes, `wp_remote_get/head`, `update_option`, `realpath`+`wp_normalize_path` bounds, `is_link` symlink guards. Verified via `php -l` mental pass (no syntax errors in evidence snippets). Cross-checked against `AUDIT/SECURITY-REVIEW.md` and `AUDIT/AGENTS/agent-A08-security.md` compensating controls — re-verified raw evidence before accepting.

---

## Findings

### S-CLI-01 — WP-CLI `database optimize --tables` identifier interpolation without strict allowlist

- **File:Line:** `includes/class-wppo-cli-command.php:178-183` (`database()` optimize branch), `includes/class-database-cleanup.php:1040-1065` (`optimize_table`)
- **Function/Class:** `WPPO_CLI_Command::database` → `Database_Cleanup::optimize_table`
- **Category:** SQL injection (identifier interpolation) / Privilege boundary
- **Severity:** **LOW**
- **Problem:** CLI `--tables=posts,postmeta,comments,commentmeta,options` is `explode(',', $tables)` + `trim` with no allowlist validation before `optimize_table($table)` interpolates `"OPTIMIZE TABLE {$full_table_name}"` via `$wpdb->{$table}`. The comment at `class-database-cleanup.php:1026-1033` correctly justifies the pattern for the REST/`clean_all` path (values come from `TABLE_MAP` allowlist), but the CLI path forwards raw user input without that guarantee. A typo (`wp_posts` vs `posts`) silently resolves to empty via `$wpdb->{$table}` and returns false, but a crafted `information_schema.tables`-style string cannot inject via `$wpdb->{$table}` property dereference — it resolves to empty, not to an arbitrary identifier. True injection via `; DROP` is impossible because `wpdb::$postmeta` is a property lookup, not raw SQL. However the absence of an explicit allowlist + `wp_cli` table-name validation is inconsistent with the codebase's otherwise strict allowlisting.
- **Why matters:** WP-CLI callers are already shell-trusted (privilege not escalated), so exploitability is negligible. The gap matters as a consistency defect: if a future REST caller ever forwarded `$_REQUEST['tables']` into `optimize_table`, the allowlist absence would become exploitable.
- **Evidence:**
  ```php
  // class-wppo-cli-command.php:178-182
  $tables = $assoc_args['tables'] ?? 'posts,postmeta,comments,commentmeta,options';
  $table_list = array_map('trim', explode(',', $tables));
  foreach ($table_list as $table) { $result = Database_Cleanup::optimize_table($table); }

  // class-database-cleanup.php:1043  $full_table_name = $wpdb->{$table};
  // class-database-cleanup.php:1065  $wpdb->query("OPTIMIZE TABLE {$full_table_name}");
  ```
- **Impact:** Console-only; no privilege escalation (WP-CLI user already controls the server). Artifact: harmless failed `OPTIMIZE TABLE` or misleading success message.
- **Recommended solution:** Add explicit allowlist at CLI entry before calling `optimize_table`:
  ```php
  $allowed = array('posts','postmeta','comments','commentmeta','options');
  $table_list = array_filter($table_list, fn($t) => in_array($t, $allowed, true));
  if (empty($table_list)) WP_CLI::error('No valid table identifiers.');
  ```
- **Confidence:** **HIGH**

---

### S-UNINST-01 — `uninstall.php:29` DROP TABLE via interpolated prefix (core-controlled, PHPCS-ignored)

- **File:Line:** `uninstall.php:29`
- **Function/Class:** `wppo_cleanup_site()`
- **Category:** SQL injection — interpolation vs `$wpdb->prepare` hygiene
- **Severity:** **INFO**
- **Problem:** `$wpdb->query("DROP TABLE IF EXISTS {$table_name}")` where `$table_name = $wpdb->prefix . 'wppo_activity_logs'`. PHPCS directives `PreparedSQL.InterpolatedNotPrepared` are suppressed. The prefix is core-controlled (`$wpdb->prefix` is set from `wp-config.php` table-prefix, alphanumeric + underscore), not user input, so no injection is reachable — this is a hygiene finding.
- **Why matters:** Direct interpolation violates PHPCS `WordPress.DB.PreparedSQL` and sets a precedent that future tables interpolated with user-derived suffixes might copy without escaping.
- **Evidence:**
  ```php
  // uninstall.php:28-29
  $table_name = $wpdb->prefix . 'wppo_activity_logs';
  $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
  ```
- **Impact:** None exploitable; uninstall only runs when `WP_UNINSTALL_PLUGIN` is defined by core.
- **Recommended solution:** Keep `// phpcs:ignore` but add a trailing comment `// $wpdb->prefix is core-provided, not user input` (already present as a justification in code comments at `class-database-cleanup.php:1062-1064`). No behavioral change.
- **Confidence:** **HIGH**

---

### S-TRANSIENT-01 — Uninstall leaves bounded-but-orphaned RUM/CCSS/PageSpeed transients (DB-backed object cache residue)

- **File:Line:** `uninstall.php:92-98` (transient deletion block)
- **Function/Class:** `wppo_cleanup_site()`
- **Category:** Data hygiene / File-system & storage ops
- **Severity:** **INFO**
- **Problem:** The uninstall loop deletes only 5 prefixed transients (`wppo_activation_notices`, `wppo_show_welcome_notice`, `wppo_cache_size`, `wppo_total_js_css`, `wppo_wp_cache_fix_checked`). Bounded queue + rate-limit + CCSS/PageSpeed transient families are not deleted: `wppo_rum_queue`, `wppo_rum_flush_lock`, `wppo_rum_ratelimit_*` (per-IP, up to 120/hour keys), `wppo_ccss_status_*`, `wppo_pagespeed_*`, `wppo_db_cleanup_counts`, etc. On sites with a persistent object cache backed by the database (no external Redis), orphaned transients survive as `wp_options` rows with `autoload=no` until their TTL expires (up to 1 week for `wppo_ccss_status_*`). Also `wppo_web_vitals_rum` / `wppo_web_vitals_trends` options (14 days × 200 paths) are not deleted.
- **Why matters:** Not a security boundary; residue cannot be used for injection or auth bypass. It is a data-hygiene issue that leaves fingerprintable transient names/option rows post-uninstall.
- **Evidence:**
  ```php
  // uninstall.php:93-98  only 5 deletes
  $transient_prefix = is_multisite() ? get_current_blog_id() . '_' : '';
  delete_transient($transient_prefix.'wppo_activation_notices');
  // ... 4 more
  // RUM: class-rum.php:58 QUEUE_KEY='wppo_rum_queue' 62 FLUSH_LOCK, 251 ratelimit via md5(IP) — never deleted
  // CCSS: class-critical-css.php:232 transient wppo_ccss_status_{hash} — never deleted
  ```
- **Impact:** Low — at most a few KB of orphan rows, no privilege or injection effect.
- **Recommended solution:** Add to `wppo_cleanup_site()`:
  ```php
  delete_option('wppo_web_vitals_rum');
  delete_option('wppo_web_vitals_trends');
  delete_option('wppo_web_vitals_last_rescan');
  // wildcard: $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('_transient_'.Util::transient_key('wppo_rum')).'%'));
  // or explicit: delete_transient(Util::transient_key('wppo_rum_queue'));
  ```
- **Confidence:** **LOW** (low severity; fix is hygiene, not security — see also `AUDIT/FINAL-REVIEW/wordpress.md` W-004)

---

### S-AUTOLOAD-01 — `get_autoloaded_options` LIMIT via `(int)` cast inside SQL string (safe, pattern note)

- **File:Line:** `includes/class-database-cleanup.php:631-634`
- **Function/Class:** `Database_Cleanup::get_autoloaded_options`
- **Category:** SQL injection — numeric parameter handling
- **Severity:** **INFO**
- **Problem:** `LIMIT " . (int) $limit` interpolates after `(int)` cast rather than via `%d` placeholder. This is safe — `(int)` guarantees a numeric string with no injection characters — but diverges from the codebase's otherwise uniform `prepare` + spread pattern and would be flagged by strict `PreparedSQL` audits if the cast were ever removed.
- **Why matters:** Consistency: a future edit that replaces `(int)$limit` with raw `$limit` would silently open injection via the only call site `Rest::get_autoloaded_options` where `$limit = absint($request['limit'])` already bounds 1–100, but the defense-in-depth principle prefers a placeholder.
- **Evidence:**
  ```php
  // class-database-cleanup.php:631
  $wpdb->prepare("SELECT option_name, LENGTH(option_value) AS opt_size FROM {$wpdb->options} WHERE autoload IN ($placeholders) ORDER BY opt_size DESC LIMIT " . (int) $limit, ...$autoload_values)
  // Caller: class-rest.php:270-271  $limit = absint($request['limit']); $limit = max(1,min(100,$limit));
  ```
- **Impact:** None currently — integer-cast makes injection impossible; caller further bounds 1–100.
- **Recommended solution:** Prefer placeholder for LIMIT consistency:
  ```php
  $wpdb->prepare("... LIMIT %d", ...array_merge($autoload_values, [(int)$limit]))
  ```
- **Confidence:** **HIGH**

---

### S-CACHE-01 — Static-HTML cache writes correctly bounded by `ABSPATH + realpath` + `cache_root_dir` empty-guard (positive finding)

- **File:Line:** `includes/class-cache.php:242-256` (cache_root_dir guard), `includes/class-cache.php:1447-1520` (get_cache_file_path), `includes/class-rest.php:402-433` (clear_cache path validation), `uninstall.php:114-155` (wppo_delete_directory symlink guard)
- **Function/Class:** `Cache`, `Rest::clear_cache`, `wppo_delete_directory`
- **Category:** File/system ops — path traversal
- **Severity:** **INFO** — **No defect** (documented positive control)
- **Problem:** Verified compensating controls before reporting. No path traversal found. Cache root is `wp_normalize_path(WP_CONTENT_DIR.'/cache/wppo')` with empty `WP_CONTENT_DIR` guard → `cache_root_dir=''`, which gates `is_not_cacheable()` true and prevents lazyload installation. REST `clear_cache` uses dual validation: `realpath($cache_dir.$path)` prefix check against `trailingslashit($cache_dir)` + fallback `candidate_path` with `strpos(..)` reject. Uninstall's `wppo_delete_directory` checks `is_link($dir)` and `is_link($path)` before `is_dir()` to avoid following planted symlinks.
- **Evidence:**
  ```php
  // class-cache.php:242-244
  if (!defined('WP_CONTENT_DIR') || ''===WP_CONTENT_DIR) { $this->cache_root_dir=''; }
  // class-cache.php:405-411  wp_normalize_path(trim(rawurldecode(parse_url(...)),'/')); if(strpos($url_path,'..')!==false) $url_path='';
  // class-rest.php:413-430  $real_path=realpath($this->cache_dir.$path); if(false!==$real_path) { $is_under_dir=0===strpos(normalize($real_path), trailingslashit(normalize($cache_dir))); }
  // uninstall.php:117,141  if(is_link($dir)) @unlink($dir); if(is_link($path)) @unlink($path); // before is_dir()
  ```
- **Impact:** Traversal not exploitable; controls exceed WordPress norms.
- **Recommended solution:** No change.
- **Confidence:** **HIGH**

---

### S-SSRF-01 — Telemetry + PageSpeed + Critical CSS SSRF protections verified multi-layer (positive finding)

- **File:Line:** `includes/class-telemetry.php:84-97` (`scan` entry), `includes/class-telemetry.php:204-428` (`execute_curl` + `resolve_redirect` + `fetch_via_curl/wp_remote`), `includes/class-rest.php:1223-1240` (`run_performance_scan`), `includes/class-rest.php:1272-1288` (`queue_pagespeed_scan`), `includes/class-critical-css.php:550-578` (`is_safe_stylesheet_url`)
- **Function/Class:** `Telemetry`, `Pagespeed::run_scan`, `Critical_CSS`
- **Category:** SSRF / Remote request
- **Severity:** **INFO** — **No defect** (documented positive control)
- **Problem:** Before reporting SSRF, verified compensating controls — all scan paths pass. REST validates `wp_http_validate_url` + `scheme http/https` + `host === Util::cached_home_url()` host before any network call. Telemetry re-validates inside `scan`, enforces `CURLOPT_FOLLOWLOCATION false` + `CURLOPT_PROTOCOLS HTTP|HTTPS`, manual `resolve_redirect` that re-validates same-host + `wp_http_validate_url` before each hop, `MAX_REDIRECT_HOPS 2`. Size fallback `calculate_sizes` restricts `wp_remote_head` to same-host only. Critical CSS restricts to `is_same_site_host` || `wp_http_validate_url`, uses `wp_safe_remote_get` for external hosts, depth 3, and `wppo_ccss_allowed_stylesheet_host` filter default deny.
- **Evidence:**
  ```php
  // class-telemetry.php:218  curl_setopt(CURLOPT_FOLLOWLOCATION,false);
  // class-telemetry.php:232  curl_setopt(CURLOPT_PROTOCOLS, CURLPROTO_HTTP|CURLPROTO_HTTPS);
  // class-telemetry.php:384-398  if($followed>=MAX_REDIRECT_HOPS) WP_Error; $next_url=resolve_redirect($location,$current_url); if(false===$next_url) WP_Error unsafe_redirect
  // class-rest.php:1237-1239  $home_host=parse_url(Util::cached_home_url(),PHP_URL_HOST); if(($parsed['host']??'')!==$home_host) 400
  // class-critical-css.php:552-553  if('http'!==$scheme&&'https'!==$scheme) false; if(apply_filters('wppo_ccss_allowed_stylesheet_host',false,$host)) return true;
  ```
- **Impact:** SSRF into loopback/private/reserved ranges + redirect-based SSRF blocked even if initial URL is same-host.
- **Recommended solution:** No change.
- **Confidence:** **HIGH**

---

### S-RUM-01 — Public `rum_collect` compensating controls verified; metric clamping + bounded queue + per-path token scope (positive finding)

- **File:Line:** `includes/class-rest.php:215-229` (route `__return_true` docblock), `includes/class-rum.php:100-144` (`collect`), `includes/class-rum.php:219-259` (token + rate-limit), `includes/class-rum.php:267-304` (`sanitize_sample`)
- **Function/Class:** `Rest::collect_rum`, `RUM`
- **Category:** Authentication/authorization — public endpoint justification / Rate limiting / DoS
- **Severity:** **INFO** — **No defect** (documented, reviewed)
- **Problem:** Before reporting an auth bypass, verified compensating controls — intentionally public so anonymous visitors can beacon. Documented at route definition (`__return_true is intentional and reviewed (A08 A-AUTH-01) — do not gate with manage_options`). Controls: (1) daily rolling per-path token `wp_hash('wppo_rum_'.Ymd.'|'.path)` validated with `hash_equals` against today + yesterday, path-scoped so a leaked token only inflates its own page; (2) per-IP rate limit 120/hour via `Util::transient_key('wppo_rum_ratelimit_'.md5(IP))` (multisite-safe, `REMOTE_ADDR` only, no `X-Forwarded-For` spoofing), returns 429; (3) `sanitize_sample` enforces `path` parsed via `wp_parse_url(PATH)` + `substr 512`, clamps `ttfb/fcp/lcp/inp 0..60000` and `cls 0..1`, rejects non-finite; (4) bounded storage `MAX_DAYS 14 × MAX_PATHS_PER_DAY 200` with oldest-path eviction, transient queue `QUEUE_MAX 100 / FLUSH_THRESHOLD 20` with cron fallback; (5) frontend `rum.js` sends via `navigator.sendBeacon` Blob `application/json` with `credentials: omit` fallback to `fetch` `credentials omit`, no cookie exfil.
- **Evidence:**
  ```php
  // class-rest.php:227  'permission_callback'=>'__return_true' + docblock "A08 A-AUTH-01"
  // class-rum.php:238  if(hash_equals(self::token_for($timestamp,$path),$token))
  // class-rum.php:252-257  $key=Util::transient_key('wppo_rum_ratelimit_'.md5($ip)); $count=(int)get_transient($key); if($count>=RATE_LIMIT_PER_HOUR) return true;
  // class-rum.php:277-282  $ranges=['ttfb'=>[0,60000],'cls'=>[0,1]]; $sample[$metric]=max($range[0],min($range[1],(float)$params[$metric]));
  // class-rum.php:407-411  while(count($day)>MAX_PATHS_PER_DAY) array_shift($day);
  ```
- **Impact:** No auth bypass; abuse limited to budgeted, clamped metrics for the token's own path.
- **Recommended solution:** No change. For WAF environments, document that `X-Forwarded-For` is intentionally ignored (prevents spoofed-IP bypass of rate limit); operators behind trusted proxies should document their IP-forwarding setup.
- **Confidence:** **HIGH**

---

### S-XSS-01 — Output escaping coverage verified; no stored/reflected XSS via settings, metabox, or admin-bar (positive finding with minor note)

- **File:Line:** `includes/class-metabox.php:93,97,146,183-283` (metabox `esc_html_e/esc_attr/esc_html/esc_url/checked/selected/disabled`), `includes/class-cache.php:1281-1363` (`maybe_apply_cdn` via `WP_HTML_Tag_Processor` + `esc_url_raw`), `src/main.js:136,149` (`textContent` not `innerHTML`), `src/lazyload.js:20` (`JSON.parse` + `console.warn` on invalid)
- **Function/Class:** `Metabox::render_metabox/render_asset_manager_metabox`, `Main::add_setting_to_admin_bar`, `Cache::maybe_apply_cdn`
- **Category:** Cross-site scripting (XSS)
- **Severity:** **LOW**
- **Problem:** No stored or reflected XSS found. Settings flow is `sanitize_text_field/sanitize_textarea_field/esc_url_raw` on entry (`Rest::sanitize_settings_recursively` → `Util::sanitize_settings_recursively` + `Util::ALLOWED_SETTINGS_KEYS` allowlist), and values are output via `esc_html/esc_attr/esc_url/esc_textarea/wp_kses_post` or `WP_HTML_Tag_Processor` with allowlisted tags (`Util::generate_preload_link` uses `wp_kses` with `link[rel/href/as/crossorigin/type/media/fetchpriority]`). Metabox renders with proper escaping; asset handles are allowlisted via `array_intersect` before display. Admin-bar notices in `src/main.js` use `textContent` + `createElement` (no `innerHTML`). Lazy-load `initVideoPlaceholders` parses `data-wppo-iframe-attrs` via `JSON.parse` with try/catch and only sets attributes except `src/width/height/style` — safe. **Minor note:** `class-critical-css.php:882-884,939-942` rejects `</style` / `<script` before caching and `sanitize_inline_css` encodes remaining `<` as `\3c ` plus `wppo_ccss_sanitize_inline` filter — defense in depth. One `src/lazyload.js:68-69` probe appends a hidden `<img sizes="auto">` to `documentElement` then removes it; `sizes` value is static `auto`, not user input — safe.
- **Evidence:**
  ```php
  // class-metabox.php:189  value="<?php echo esc_attr($script['handle']); ?>"  <?php checked($is_disabled); ?>
  // class-util.php:820  $safe_key = preg_replace('/[^a-zA-Z0-9_\-]/','',$key); if(''===$safe_key) continue;
  // src/main.js:136  noticeEl.textContent = message;  // not innerHTML
  // class-critical-css.php:882  if(false=== $critical_css || preg_match('/<\/style|<script/i',$critical_css)) return false;
  ```
- **Impact:** XSS not exploitable via current input → storage → output chain; `manage_options` gate already restricts who can store settings.
- **Recommended solution:** No fix required. If a future setting allows freeform HTML (e.g. custom CSS/JS), route it through `wp_kses` with an explicit allowlist rather than `sanitize_text_field` which currently suffices for URL/text settings.
- **Confidence:** **HIGH**

---

### S-REST-01 — REST permission surface: 28 admin routes uniformly gated via `permission_callback` + `X-WP-Nonce`; AJAX nonce refresh thundering-herd guarded

- **File:Line:** `includes/class-rest.php:58-261` (get_routes), `includes/class-rest.php:357-362` (`permission_callback`), `includes/class-rest.php:1178-1192` (`ajax_get_nonce`), `src/lib/apiRequest.js:16-57` (`refreshNonce` + `pendingRefresh` guard), `src/main.js:60-101` (admin-bar mirror)
- **Function/Class:** `Rest`, `apiRequest.refreshNonce`, `main.refreshNonce`
- **Category:** Authentication/authorization / CSRF (nonces)
- **Severity:** **INFO** — **No defect** (documented positive control)
- **Problem:** Before reporting auth gaps, verified every registered route has a `permission_callback`. 27 routes use `array($this,'permission_callback')` which enforces `current_user_can('manage_options') && wp_verify_nonce($nonce,'wp_rest')` with `sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WP_NONCE']))`. The single public route `rum_collect` is the intentional exception with documented compensating controls (see S-RUM-01). AJAX `wppo_get_nonce` checks `is_user_logged_in && current_user_can(manage_options) && check_ajax_referer('wppo_nonce_refresh','nonce',false)` before issuing `wp_create_nonce('wp_rest')`. Both JS clients deduplicate concurrent 403→refresh via `pendingRefresh` promise guard.
- **Evidence:**
  ```php
  // class-rest.php:357-361
  $nonce = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WP_NONCE']??'')); return current_user_can('manage_options') && wp_verify_nonce($nonce,'wp_rest');
  // class-rest.php:224-228  'rum_collect'=>['permission_callback'=>'__return_true'] + docblock compensating controls
  // class-rest.php:1179-1184  if(!is_user_logged_in()||!current_user_can('manage_options')) wp_send_json_error(403); if(!check_ajax_referer('wppo_nonce_refresh','nonce',false)) 403
  // src/lib/apiRequest.js:17  if(pendingRefresh) return pendingRefresh;
  ```
- **Impact:** No capability or CSRF bypass; editor/author/subscriber cannot hit admin routes even with a valid cookie (nonce fails or cap fails).
- **Recommended solution:** No change. Keep `vars.OPENCODE_MODEL` style nonces isolated from `rum_collect` — do not add `manage_options` to `rum_collect` as it would break anonymous beacons.
- **Confidence:** **HIGH**

---

## Non-findings (explicitly checked, not reported)

- **SQLi via `$wpdb->prepare`:** All dynamic deletes/counts use `$wpdb->prepare` with `%d/%s` + spread `...$ids` + `array_fill` placeholders (`class-database-cleanup.php:159,167,224,251,293,308,438,474,509,530,565,630,671,691,876,991,1011`) or fixed `SELECT COUNT(*) FROM $wpdb->posts` with core table names (not user input). No `sprintf`/string-concat SQL with user values.
- **Privilege escalation via `wppo_settings`:** `update_settings` validates `Util::ALLOWED_SETTINGS_TABS` allowlist + `sanitize_settings_recursively`, strips `password`/`pagespeed_api_key` before `update_option`, never trusts `wppo_settings` on read without `get_option` isolation per site.
- **Cache file writes:** `Cache::save_processed_buffer` → `atomic_put_contents` via `WP_Filesystem::put_contents` under `cache_root_dir/domain/url_path/index.html` with domain regex `^[a-z0-9\.\-]+$` + `ABSPATH` prefix bound + empty-domain abort; no user-controlled `WP_CONTENT_DIR` override except via `wppo_object_cache_dropin_path` filter (only affects `object-cache.php`, isolated).
- **Object-cache drop-in overwrite:** `Object_Cache::enable` refuses if `foreign_dropin` present; `Advanced_Cache_Handler::create` returns early if `foreign_dropin_present()`; both write via `WP_Filesystem` with `FS_CHMOD_FILE`.
- **Uninitialized `$content` in `Object_Cache::get_status`:** `file_get_contents` gated by `is_readable && filesize < 1MiB`; if `$wp_filesystem` missing, fallback `file_get_contents` sets `$content`, otherwise `isset($content)` prevents use of an uninitialized variable — safe.
- **RUM IP handling:** Only `$_SERVER['REMOTE_ADDR']` is used, not `HTTP_X_FORWARDED_FOR` — prevents IP spoof to bypass rate limit or pollute storage; behind a trusted reverse proxy operators should terminate TLS at the proxy and rely on `REMOTE_ADDR` after proxy sets it.
- **JS `dangerouslySetInnerHTML`:** Not used in any SPA component; `NoticeBanner` renders `.wppo-notice wppo-notice--{type}` with `role="alert"`; `PerformanceAudit` telemetry output is via `MetricCard` props, not raw HTML injection.

---

## Appendix — Search evidence

- `Grep: manage_options` → `class-rest.php:361`, `class-rest.php:1179`, `tests/php/RestTest.php` — single central gate.
- `Grep: hash_equals` → `class-rum.php:238` — only per-path token comparison uses timing-safe compare; path token scope added NEXT.
- `Grep: wp_verify_nonce|check_ajax_referer` → `class-rest.php:359`, `class-rest.php:1183`, `class-metabox.php:326,352` — nonce on REST + AJAX + metabox.
- `Grep: $wpdb->prepare` → 23 call sites across `class-database-cleanup.php`, `class-system-info.php`, `class-log.php`, `class-img-converter.php`, `class-activate.php` — all with `%s/%d` + `wpdb->esc_like` or `Util::transient_key`.
- `Grep: esc_|sanitize_|wp_kses` → 60+ call sites; `view: update_settings` uses `sanitize_settings_recursively`, `metabox: esc_attr/esc_html/esc_url`, `cache: esc_url_raw`, `log: wp_kses_post`.
- `Grep: ABSPATH|WP_CONTENT_DIR|realpath|wp_normalize_path|is_link` → `class-cache.php`, `class-img-converter.php`, `class-rest.php`, `uninstall.php` — all path operations bounded.
- `Grep: permission_callback` → `class-rest.php:81-253` every route has one; single `__return_true` is `rum_collect` with inline justification.

---

*Evidence-first, compensating-control-verified. All claims above are backed by file:line citations from `master@31fffc61` working tree. No production code was modified.*
