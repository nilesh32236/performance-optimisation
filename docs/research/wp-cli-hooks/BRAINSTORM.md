# Brainstorm — Options per Major Improvement (A Minimal / B Recommended / C Long-Term)

**Date:** 2026-08-28 · **Plugin:** `performance-optimisation` v1.9.0  
**Method:** For every major improvement area below, generate **Option A (minimal)**, **Option B (recommended)**, **Option C (long-term)**. Compare `Complexity` / `Perf` / `Compat` / `DX` / `Testing` / `Migration` / `Future` / `Risk` and pick per `ADVERSARIAL-REVIEW.md` survivors (`RETAIN`/`MODIFY`/`REJECT`). All options cite `file:line` + handbook source. Research-only.

> Inputs: `WP-CLI-CURRENT.md` (973 lines, 7 subcommands, `WPPO_CLI_Command:42` → `Max` `includes/class-main.php:472-474`), `WP-CLI-RESEARCH.md` (§1-§16 handbook, §6 `Formatter`, §7 `make_progress_bar`, §8 `confirm/--dry-run`, §11 `--url/--network`), `HOOK-AUDIT.md` (272 hits), `HOOK-GAPS.md` (32 gaps, 17 cats), `ECOSYSTEM-RESEARCH.md` (§4.1.1-4.1.10), `PERF-RESEARCH.md` (context cost `§2.2 hot-path` vs `§6 savings`), `ARCH-RESEARCH.md` (§2 services, §5 bootstrap fences), `MIGRATION-COMPATIBILITY.md` (12 change IDs `C-WPCLI-01—C-ALIAS-12`), `TEST-PLAN.md` (harness gap).

---

## 1. CLI Help (`@synopsis` / `@subcommand` / `@when` / longdesc)

| Option | What | Complexity | Perf | Compat | DX | Testing | Migration | Future | Risk |
|--------|------|------------|------|--------|----|---------|-----------|--------|------|
| **A. Minimal** | Fix 5 synopses `<action>`→`[<action>]` only (`cache:49`, `database:131`, `image:301`, `object-cache:757`, `pagespeed:880` each add `[<action>]`) + one sentence `In multisite use wp --url=<site> wppo …` help note (`MIGRATION C-WPCLI-02`). No `--- default/options:` enumerations. | 5 lines docblock | none | `none` — help only | `wp help wppo cache` honest (optional) | `grep docs` 0 | `@since NEXT` on 5 methods | Blocks `E`/`F` `--format` divergence | **Low** |
| **B. Recommended — handbook-correct** | A + enumerate `--- default: clear + options: clear,preload,status ---` etc. for all 5 actions so WP-CLI validates **before** `invoke` (`Error: Parameter errors: Invalid value specified for '<action>'` fast fail vs manual `WP_CLI::error` at `:93-96,206-209` after boot). Add `## EXAMPLES` `Sample Output` lines per `documentation-standards` `ECOSYSTEM-RESEARCH.md §4.1.2`. Per `ADVERSARIAL W-01 RETAIN` (zero runtime). | 20 lines docblock | none | `none` — pre-invoke validator same exit 1 as current runtime error | `typo wppo cache claer` fails before `Cache::clear_cache` | `WppoCliRegistrationTest` synopsis parse | Help only, no code | Enables `wp --prompt` interactive fill | **Low** |
| **C. Long-term — split `--help` registry** | B + third-arg `synopsis` array at `WP_CLI::add_command` (`WP-CLI-RESEARCH.md §2` `synopsis => [{type:positional,…},{type:assoc,…}]`) + `@before_invoke` / `@after_invoke` hooks for `--network` sweep | 40 lines + `class-wppo-cli-command.php:12` `class_exists WP_CLI_Command` guard | none | `none` | Machine-synopsis for `wp cli update` | Needs `WP_CLI\Dispatcher` mock | `composer.json` `classmap` vs `psr-4` `MIGRATION C-ALIAS-12` | Idealistic — plugin-loaded `@when` already ignored (handbook "most WP-CLI hooks fire before WP loaded") | **Medium** — not needed |

**Pick: B recommended** — `W-01 RETAIN`, handbook "longdesc options validated before invoke" vs manual `WP_CLI::error` inside method (`WP-CLI-CURRENT.md §2.2`) is free win.

---

## 2. Output Formatting (`--format` / `--fields` / `--field` / `Formatter` / `format_items`)

| Option | What | Complexity | Perf | Compat | DX | Testing | Migration | Future | Risk |
|--------|------|------------|------|--------|----|---------|-----------|--------|------|
| **A. Minimal** | Keep `settings get --format=json\|yaml` at `:690-703` Spyc/yaml_emit only; others stay `wp_json_encode(PRETTY)` + `log` `:202,385,808,926,965`. Document "pipe to `jq`" in help. | 0 lines | none | `none` | `wppo cache status` 3 `log(sprintf…)` `:87-89` not `jq`-parseable — scripts parse logs | None | None | Debt accumulates | **Low** (but blocks scripting) |
| **B. Recommended — handbook canonical** | Add `Formatter` / `format_items` + `[--format=<format>]` to `cache status`, `database counts`, `image status`, `object-cache status`, `pagespeed results`, `system-info` — guard `class_exists('WP_CLI\Formatter') → fallback wp_json_encode` (`MIGRATION C-WPCLI-03`). Default `table` human (preserve `log` lines when `--format` omitted), alt `json` returns `{"size":…,"cached_pages":…}`. Narrow `pagespeed results` to `table\|json` only (reject `csv` for large `lighthouseResult`). Add `[--fields]/[--field]` where tabular (`system-info` `--fields=php,server`) (`WP-CLI-RESEARCH.md §6` `Formatter` `table\|json\|csv\|yaml\|count\|ids`). Per `ADVERSARIAL W-02 RETAIN (MODIFY scope)`. | ~60 lines (`display_items` wrapper helper 10 lines + per-cmd 8 lines) | none — `Formatter` is string builder, not per-request hot | `none` — additive; scripts that parsed old `log` lines keep `table` default, migrate to `--format=json` when ready; `Formatter` stable WP-CLI 1.0+ | `wp wppo database counts --format=table` human + `| jq` via `--format=json` | `TEST-PLAN.md §4.1` `should support table/json/csv/yaml/count` matrix + `Util::$home_url_cache` leak fix | `@since NEXT` on 6 flags | Unblocks all CI gates |
| **C. Long-term — full `wp option get` delegation** | B + `wp wppo settings get` delegates to `wp option get wppo_settings --format=json` after sanitize + per-tab filter (`ECOSYSTEM-RESEARCH.md §4.2` `litespeed-option all --format table\|json\|csv\|yaml\|ids\|count` + `WC_CLI` `wc product list --format`) | 30 lines extra delegation | none | `low` — raw option change breaks tab filtering | Woo/LSCWP parity | Needs `WP_CLI::runcommand` mock | `--format` already needs `Formatter` | Over-engineered — tab filtering is value-add over raw `wp option` | **Medium** |

**Pick: B recommended** — `W-02` explicit "even admin commands need format" + Woo `product list --format=json` proof `ECOSYSTEM-RESEARCH.md §4.1.4`. Limit `pagespeed results` to `table|json` per adversarial.

---

## 3. Dry-Run (`--dry-run` preview without `DELETE`/`unlink`/`update_option`)

| Option | What | Complexity | Perf | Compat | DX | Testing | Migration | Future | Risk |
|--------|------|------------|------|--------|----|---------|-----------|--------|------|
| **A. Minimal** | No `--dry-run` — use existing `database counts` at `:202` (9 keys) + `cache status` walk `class-cache.php:2184` + `image status total_pending:385` as manual preview. Document in help. | 0 | none | `none` | Operator runs two commands (preview + mutate) — error prone (`counts` 9 keys not per-type `would delete 1,243 revisions`) | None | None | No gate | **Low** |
| **B. Recommended — handbook twin with `counts`/`status` reuse** | Add `[--dry-run]` boolean to `cache clear`, `database cleanup`, `settings import` (retain per `ADVERSARIAL W-04 MODIFY`; **reject** `image --dry-run` — `status` already `total_pending:385`). `database cleanup --dry-run` reuses `get_counts` not `delete_in_batches` `:138-180` at `class-database-cleanup.php:138`; `cache clear --dry-run` reuses `get_cache_stats` not `unlink` at `class-cache.php:2030`; `settings import --dry-run` validates `json_decode`+allowlist `ALLOWED_SETTINGS_KEYS:644`+`sanitize_settings_recursively:654` at `class-wppo-cli-command.php:644,654` without `update_option` `:671`. No flag for `optimize`/`preload`. Per `developer.wordpress.org/cli/commands/search-replace --dry-run` (`WP-CLI-RESEARCH.md §8`) canonical. | ~40 lines (`get_flag_value` check + early `log` + return) | `none` — reads only; skips `DELETE`/`OPTIMIZE`/`unlink`/`update_option` | `none` — new boolean `[--dry-run]` never breaks existing calls (default off) | `wp wppo database cleanup --type=all --dry-run --format=json \| jq .would_delete` before nightly cron | `TEST-PLAN.md §4.1` dry-run matrix D13 + `WPPO_DB_Mock` `prepare` assertion | `@since NEXT` | Enables safe automation |
| **C. Long-term — `START TRANSACTION / ROLLBACK` mock writes** | B + wrap mutations in `START TRANSACTION` then `ROLLBACK` (DeliciousBrains recipe `WP-CLI-RESEARCH.md §10`) to actually `DELETE LIMIT 1000` then rollback — most faithful row count | 60 lines + DB transaction | `low` — actually deletes then rolls back (still locks) | `low` — MyISAM/`wp_postmeta` MyISAM fallback `class-database-cleanup.php:1040-1088` not transactional | Most accurate | Needs InnoDB assumption | `optimize_table:1040` `OPTIMIZE TABLE` not rollbackable | `database optimize --dry-run` impossible (lock) | **Medium** — overkill |

**Pick: B recommended** — `W-04` retain `cache`+`database`, reject `image`; handbook `search-replace --dry-run` is read-not-delete, not transaction.

---

## 4. Multisite (`--url` global vs `--network` sweep vs `--blog_id` CSV)

| Option | What | Complexity | Perf | Compat | DX | Testing | Migration | Future | Risk |
|--------|------|------------|------|--------|----|---------|-----------|--------|------|
| **A. Minimal — docs only** | Keep relying on WP-CLI global `--url` (`WP_CLI\Runner::set_url_params` before `after_wp_load` `MIGRATION C-MULTI-06` `handbook/references/config --url` "In multisite, how target site is specified") without code. Add one-line help note `In multisite use wp --url=<site> wppo …` (`PERF-RESEARCH.md §2.4` gap "never documents it"). No `--network`. | 1 line help | none | `none` | `wp --url=https://sub.example.com wppo cache clear` works today but `--network` all-sites requires loop in shell `for id in $(wp site list --field=url); do wp --url=$id wppo cache clear; done` | None | None | Shell loop not atomic | **Low** |
| **B. Recommended — `--network` with strict guards** | A + `[--network]` opt-in sweep per `ADVERSARIAL W-07 RETAIN (MODIFY)`: `function_exists('get_sites')` WP 4.6+ safe vs 6.2 floor, `is_multisite() && function_exists('is_multisite') && is_multisite()` try/catch at `class-util.php:788` pattern, paginated `get_sites(['number'=>100,'offset'=>…])` loop `uninstall.php:193-217` (not unbounded), `try{switch_to_blog($id)}finally{restore_current_blog()}`, per-blog `Util::clear_settings_cache()` :91-106 + `Util::$home_url_cache`  reset `:112,214`, `make_progress_bar('Processing sites',count($sites))` + `warn object-cache flush is all-sites` at `templates/object-cache.php:532` (`MIGRATION C-MULTI-06/07`). **Reject** `--blog_id` CSV (duplicates `--network`; Rocket compat but ambiguous `W-07`). | ~50 lines (`foreach_site()` helper 30 lines + per-cmd 4 lines) | none — paginated, respects `--limit`, flushes per batch | `none` — additive; single-site `is_multisite`→false → `error --network only for multisite` exit 1 not fatal | `wp wppo cache clear --network --yes --dry-run` all-sites preview atomic in one PHP process | `WppoCliMultisiteTest` Brain Monkey `switch_to_blog`/`restore_current_blog` count equality (`TEST-PLAN.md §5.5` + `MIGRATION C-MULTI-07` `*Test.php` discovery rule) | `@since NEXT` on `--network` | Enables nightly `database cleanup --type=all --network` |
| **C. Long-term — `@alias` + `--blog_id=<comma-list>` + `wp package install` external** | B + `--blog_id=2,4,6` CSV compat (Rocket `clean --blog_id=2,4` via `explode(',')` `ECOSYSTEM-RESEARCH.md §4.1.8`) + external `wp-cli-package` split `github.com/wp-media/wp-rocket-cli` (73★) | +20 lines CSV parse + `composer.json type wp-cli-package` | `none` | `low` — `--blog_id` + `--network` ambiguity (both sweep) | Muscle-memory for Rocket users | Two sweep paths to test | Package `type wp-cli-package` split bloat | Shadow manual load `AGENTS.md:18` no PSR-4 | **High** — not needed |

**Pick: B recommended** — `W-07` "global `--url` is handbook and already works — retain doc note, `--network` retain with strict guards; reject `--blog_id` CSV".

---

## 5. Hooks — Cache Veto / Buffer / Preload / CDN / DB / RUM / Object-Cache

Grouped by `HOOK-GAPS.md` verdict (`ADVERSARIAL §2`).

### 5.1 Cache veto `wppo_should_cache_request` (P0) + `wppo_should_optimise_for_user` (P0)

| Option | What | Complexity | Perf | Compat | DX | Testing | Migration | Future | Risk |
|--------|------|------------|------|--------|----|---------|-----------|--------|------|
| **A. Minimal** | Keep `DONOTCACHEPAGE` constant + `wppo_cache_page_html:1661` mutate HTML (current). No new filter. | 0 | `none` | `none` | Forces `define DONOTCACHEPAGE` before cache decision (too early for consent/geo) or post-hoc HTML mutate not preventing `save_processed_buffer` FS write + `wppo_preload_cron_lock:288` stampede | None | None | Blocks agency | **Low** (but blocks) |
| **B. Recommended** | Single `apply_filters('wppo_should_cache_request', true, $request_uri, $url_path, $domain)` at `class-cache.php:1505 is_not_cacheable` entry + `class-cache.php:1755 maybe_store_cache` entry (both legacy `start_output_buffer:550` + 6.9+ `stash_cache:546` paths) + `wppo_should_optimise_for_user` at `class-main.php:369 should_optimise_for_logged_in` + `class-cache.php:297 is_cache_allowed_for_current_user` — per `HOOK-GAPS G-01`/`G-28` spec, `ADVERSARIAL G-01`/`G-28` RETAIN. | 6 lines `apply_filters` | `none` — front-end only, `has_filter` short-circuit | `none` — default `true` preserves behavior; `false` short-circuits before `save_cache_files` lock | One filter covers membership/A/B/consent/WPML `?lang` shards | `HookFilterModificationTest` at `class-image-optimisation.php:1942` pattern + `wppo_cache_page_html` existing test | `@since NEXT` | Unblocks forks |
| **C. Long-term** | B + per-route `wppo_should_cache_request_for_{$url_path}` dynamic filter + `wppo_cache_vary_headers` for `Vary: Cookie` | 20 lines | `low` — extra dynamic filters per URL | `low` — `has_filter` per dynamic name | More granular but not needed | Same | Extra `has_filter` per request | Speculative | **Medium** |

**Pick: B recommended** — `G-01`/`G-28` P0 blocking veto, adversarial retain, 1 line fixes fork.

### 5.2 Cache observability (`wppo_cache_written` / `miss` / `hit`)

| Option | What | Complexity | Perf | Compat | DX | Testing | Migration | Future | Risk |
|--------|------|------------|------|--------|----|---------|-----------|--------|------|
| **A. Minimal** | Keep `wppo_before_cache_clear:2032` + `wppo_after_cache_clear:2074` only (invalidation signal, no generation). APM counts via `wppo_cache_page_html:1661` side effect. | 0 | `none` | `none` | APM must patch `Cache` to count | None | None | No hit-rate | **Low** |
| **B. Recommended** | Add `do_action('wppo_cache_written', $url,$file_path,$generation_ms)` after `save_processed_buffer` success `:1741` + `do_action('wppo_cache_miss', $url,$reason)` after `maybe_store_cache` false `:1661`; **reject** `wppo_cache_hit` in `templates/advanced-cache.php` drop-in (would require booting `wp-load.php` before `plugins_loaded` — breaks zero-boot promise `PERF-RESEARCH.md §2.2`) per `ADVERSARIAL G-02 MODIFY`. | 6 lines | `none` — generation only | `none` — additive | New Relic/Prometheus `X-WPPO-Cache: HIT` header + `access.log` for hit (docs-only) | `HookInvocationTest` `do_action` capture | `@since NEXT` | Complete without drop-in boot |
| **C. Long-term** | B + drop-in `X-WPPO-Cache: HIT` header injection + `wppo_debug_log` `class-cache.php:282` wired to `error_log` | 10 lines + FS `advanced-cache.php` rewrite per version `class-main.php:997-1023 maybe_run_version_upgrade` | `none` but requires drop-in regen per version bump | `low` — drop-in `template/object-cache.php` size limit | APM via `wppo_debug_log` | Needs drop-in E2E | `wppo_enable_context_fences` rollback filter | **Medium** — drop-in regen risk |

**Pick: B recommended** — `G-02` modify.

### 5.3 Buffer pipeline `wppo_buffer_stage` 6 stages

| Option | What | Complexity | Perf | Compat | DX | Testing | Migration | Future | Risk |
|--------|------|------------|------|--------|----|---------|-----------|--------|------|
| **A. Minimal** | Keep single `wppo_cache_page_html:1661` after minify/used-CSS/CDN pipeline + `script_loader_tag:10 :515,525` + `style_loader_tag:9 :679` per-asset — already cover defer/delay. | 0 | `none` vs 6× | `none` | 3rd-party consent/CSP plugins copy `wppo_cache_page_html` | Covered | None | Single point enough | **Low** |
| **B. (Rejected) Stepwise** | 6× `apply_filters('wppo_buffer_stage',$buffer,$stage,$url)` at `class-cache.php:1243 process_buffer_only` image→fonts→minify→used-CSS→CDN (`stage enum before_image|after_image|…|after_cdn`) + `wppo_buffer_before_save` alias (`HOOK-GAPS G-04`) | 30 lines + 6× per front-end hit | `low` ×6 → 0.2-0.6ms on 40-script pages (`HOOK-AUDIT §2` each <0.1ms but 6×) | `none` | More insertion points for Bunny/KeyCDN dual-origin | 6 extra specs | 6 docs entries | Not proven need (consent can use `wppo_cache_page_html`) | **Medium** — perf ×6 |
| **C. Long-term** | A + single `wppo_buffer_before_save` symmetry alias before `wppo_cache_page_html` if symmetry proven | 2 lines | `none` | `none` | Symmetry | 1 test | `@since NEXT` | Future-proof without hot-path ×6 | **Low** |

**Pick: A recommended** — `ADVERSARIAL G-04 REJECT` (6 stages deferred, CSP nonce via `wppo_cache_page_html`).

### 5.4 Preload / Sitemap tuning (`G-05`)

| Option | What | Complexity | Perf | Compat | DX | Testing | Migration | Future | Risk |
|--------|------|------------|------|--------|----|---------|-----------|--------|------|
| **A. Minimal** | Keep hard caps `200` `:301` + `500` `get_sitemap_urls(500)` `class-cron.php:364,487` + `15s:496` + single `wppo_cron_discovery_limit:666` | 0 | `none` (cron 5-hourly) | `none` | 10k-post/50M `postmeta` needs smaller tuning — not without fork | None | None | Not needed | **Low** |
| **B. Recommended** | Add `wppo_preload_batch_size` (200 `:301`) + `wppo_sitemap_preload_limit` (500 `:364`) + `wppo_preload_sitemap_urls` (`string[]` `:487`) — **reject** `wppo_sitemap_deadline_seconds` (15s `:496` 50 child sitemaps) + `wppo_should_preload_page` (per-page veto duplicates `excludePreloadCache` at `class-cron.php:271-280`) per `ADVERSARIAL G-05 RETAIN (MODIFY)`. | 6 lines `apply_filters` | `none` — cron only | `none` — defaults keep `200`/`500` | Headless `sitemap-custom.xml` injection, VPS smaller cap | 3 `apply_filters` + consumer test | `@since NEXT` 3 | Covers 90% with 3 filters |
| **C. Long-term** | B + deadline + per-page veto `wppo_should_preload_page:283` loop | 10 lines + per-page `has_filter` check | `low` — per-page veto duplicates UI `excludePreloadCacheUrls` | `low` | Per-page granularity beyond UI | Extra test | 5 filters | Diminishing returns | **Medium** |

**Pick: B recommended**.

### 5.5 CDN per-asset (`G-07`) + combine/minify/defer granularity (`G-06/G-08/G-09`)

| Option | What | Complexity | Perf | Compat | DX | Testing | Migration | Future | Risk |
|--------|------|------------|------|--------|----|---------|-----------|--------|------|
| **A. Minimal** | Keep global `cdnURL` `:1357` + `wppo_litespeed_can_cdn:1349` + `wppo_exclude_minification:2747,2849` with `$type` arg + `wppo_exclude_defer_js:701`/`exclude_delay_js:722` arrays applied once in `setup_hooks()` | 0 | `none`/`low` | `none` | Dual-CDN impossible; strategy-specific `checkout.js never delayed` impossible | None | None | Limited | **Low** |
| **B. Recommended** | **A +** `wppo_cdn_url` (mutate base) + `wppo_cdn_should_rewrite` (veto `/private/*`) at `class-cache.php:1357 maybe_apply_cdn` entry + `while(next_tag):1382` per-tag loop — `ADVERSARIAL G-07 RETAIN` + `wppo_delay_should_delay(bool,$handle,$src,$strategy)`:strategy at `class-main.php:515,722` per-tag loop — `ADVERSARIAL G-09 RETAIN (MODIFY)` (keep delay only; **reject** `wppo_defer_should_defer` — duplicates native `wp_script_add_data(strategy)` `:523` + `wppo_minify_should_minify` `G-08 REJECT` + `wppo_combine_css_should_combine:G-06` docs-only defer) | 12 lines `apply_filters` | `low` — per asset/tag, `has_filter` short-circuit | `none` — default preserves global CDN/array | Cloudflare R2+Bunny split, Woo checkout `never delay` | `HookFilterModificationTest` per-handle | `@since NEXT` 3 | Balanced |
| **C. Long-term** | B + per-handle combine `wppo_combine_css_should_combine:649` + `wppo_minify_should_minify` distinct per `exclude_css/js` + `wppo_placeholder_*` `:725,776` | +20 lines `apply_filters` per style on `wp_enqueue_scripts PHP_INT_MAX` | `low` × styles (10–20) | `none` | Tailwind `@import` order-sensitive use-case | More specs | Alias chain | Order-sensitive Tailwind proven later | **Medium** |

**Pick: B recommended** — `G-07`/`G-09` survive narrowed, `G-06`/`G-08` deferred to docs.

### 5.6 Image conversion/serve/lazy (`G-11`/`G-12`/`G-13`)

| Option | What | Complexity | Perf | Compat | DX | Testing | Migration | Future | Risk |
|--------|------|------------|------|--------|----|---------|-----------|--------|------|
| **A. Minimal** | Keep `wppo_filesize_limit_bytes:402` + `wppo_max_image_dimensions:422` + `wppo_convert_gain_map_images:360` globals + `excludeConvertImages` / `excludeFirstImages` / `Accept` header gate at `class-image-optimisation.php:887` | 0 | `none`/`low` | `none` | Photography quality per size impossible; external hotlink forced rewrite | None | None | Not enough | **Low** |
| **B. Recommended** | Add `wppo_should_convert_image(bool,$source_path,$format)` at `class-img-converter.php:319` + `wppo_conversion_quality(int,$mime,$size,$path)` at `:377` + `wppo_image_converted` action at `:357` (`G-11` RETAIN); keep **veto only** `wppo_next_gen_should_serve(bool,$img_url,$format,$exclude_imgs)` at `class-image-optimisation.php:887` — **reject** `wppo_next_gen_image_url` mutate (double-rewrite `cdnURL:1357` risk) per `G-12 MODIFY`; keep single `wppo_lazy_should_lazyload(bool,$src,$img_tag,$index)` at `add_delay_load_img` ~1400 before `data-src` swap — **reject** `wppo_lazy_exclude_first_images` count (duplicates setting) per `G-13 RETAIN`. | 12 lines `apply_filters` | `none` — conversion only (upload/cron/AS) or `low` per image | `none` — default `true`/82 | Photography 90 hero/70 thumbnail, Unsplash veto, Woo gallery 2 eager | `HookFilterModificationTest` `wppo_should_convert_image` etc. | `@since NEXT` 4 | Focused |
| **C. Long-term** | B + placeholder `wppo_placeholder_type/color/lqip_data_uri` at `class-img-converter.php:725,776` + `class-image-optimisation.php:~290` (`G-10` P2) for blurhash | +12 lines per image buffer | `low` per image | `none` | Blurhash vs LQIP per image | Needs design-system proof | Extra filters | Not proven | **Medium** |

**Pick: B recommended** — `G-11`/`G-12`/`G-13` survivors narrowed per adversarial.

### 5.7 DB lifecycle/tuning (`G-15`/`G-16`)

| Option | What | Complexity | Perf | Compat | DX | Testing | Migration | Future | Risk |
|--------|------|------------|------|--------|----|---------|-----------|--------|------|
| **A. Minimal** | Keep `wppo_database_cleanup_completed:737` only for `type=all` at `clean_all()` — per-type cleaners `clean_revisions` etc. fire **no** action (`HOOK-AUDIT §6` gap); `get_revision_defaults:753` `dbRevMaxAge/dbRevKeepLatest` hard bounds `max_age 1-365 keep 1-100` no filter | 0 | `none` | `none` | Metrics `database cleanup --type=revisions` get none — silent gap P0 | None | None | Silent | **High** |
| **B. Recommended** | Add `do_action('wppo_before_database_cleanup',$type)` before `invoke_cleanup_method:714` loops + `do_action('wppo_database_cleanup_type_completed',$type,$result)` after at `:935` (even for single-type REST/CLI path `:237-277`) — keep `all` at `:737` unchanged (`G-15` RETAIN P0) + `wppo_db_batch_size:138` (override `1000`) + `wppo_db_revision_defaults:753` (`[$max_age,$keep],$settings`) — **reject** `wppo_db_optimize_max_bytes:1040` + `should_optimize` (host disables via not running `optimize`) per `G-16 MODIFY`. | 10 lines `apply_filters/do_action` | `none` — cleanup/cron only | `none` — additive; clamp bounds `max(1,min…)` | Prometheus `wppo_db_cleanup_rows` per type, legal-hold `keep 20` | `HookInvocationTest` `do_action type_completed` | `@since NEXT` 4 | Essential |
| **C. Long-term** | B + `wppo_db_optimize_max_bytes` 1 GiB literal `:1040` + `should_optimize` per table | 6 lines + per table | `none` | `low` — must guard MyISAM fallback | Per-table `optimize` veto | Extra specs | 2 more | Not needed | **Medium** |

**Pick: B recommended** — `G-15` P0, `G-16` narrowed.

### 5.8 RUM sampling/privacy (`G-17`/`G-18`) + Object-cache (`G-19`/`G-22`) + Settings veto (`G-20`) + CDN purge (`G-27`)

| Option | What | Complexity | Perf | Compat | DX | Testing | Migration | Future | Risk |
|--------|------|------------|------|--------|----|---------|-----------|--------|------|
| **A. Minimal** | No sampling/RUM bounds filters; `wppo_redis_allow_request_password:1130` only hatch; no `wppo_settings_before_update` veto (WP `pre_update_option_wppo_settings` at `class-util.php:245-248` + `class-main.php:789` already) | 0 | `none` | `none` | 1M PV/day unbounded `wppo_web_vitals_rum`; managed-host sentinel config forces `redis-connect-helper.php` patch | None | None | Blocks staging | **Low** |
| **B. Recommended** | `G-17 RETAIN` `wppo_rum_should_collect:121` + `wppo_rum_sample_rate:189` + `wppo_rum_collect_args:121` + `wppo_rum_rate_limit:275` (privacy 1% sampling; `G-18 REJECT` retention bounds — document `rum_enabled` toggle, single `wppo_rum_max_days` only if compliance proves need); `G-19/G-22` retain `wppo_object_cache_config:252` + `wppo_cli_redis_config:864` (6→10 key fix `W-10` RETAIN; **reject** `before_enable/disable/flushed` actions — `ping:811-819` `Object_Cache::ping:205-238` + `Log::add` sufficient); `G-20 MODIFY` retain `wppo_settings_before_update(bool,$tab,$sanitized,$old):464` veto `false→WP_Error 400` (reject `sanitize`+`after_update` — use core `pre_update_option_wppo_settings`); `G-27 MODIFY` retain `wppo_purge_urls:193,212` CDN/Edge before HTTP purge (reject duplicate `should_purge` mix — one veto enough). | 20 lines `apply_filters` | `none` — beacon `collect:121` early return saves storage, admin/CLI only | `none` — defaults `true`/`1.0`/`120`/`host,port,…` | 1% sampling, cluster `mode,nodes`, policy `cdnURL` deny, CDN purge absolute URL | `HookFilterModificationTest` `wppo_rum_should_collect` etc. | `@since NEXT` 7 | Focused |
| **C. Long-term** | B + `G-18` 4 filters `MAX_DAYS=14` `:36`/`MAX_PATHS_PER_DAY=200` `:43`/`QUEUE_MAX=100` `:74`/`FLUSH_THRESHOLD=20` `:82` + `wppo_object_cache_before_enable/disable` actions + `wppo_rest_routes` at `class-rest.php:58` (`G-21 REJECT` — custom endpoints belong in `custom/v1`; core `rest_pre_dispatch` exists `developer.wordpress.org/reference/hooks/rest_pre_dispatch/`) | +20 lines `apply_filters`/`do_action` | `none` but extra store-path filters | `low` — must clamp `MAX_DAYS` to avoid unbounded option | Compliance 30-day retention | Needs compliance proof | 10 more hooks + docs | Overkill | **Medium** |

**Pick: B recommended** — 7 filters survive narrowed; `G-18`/`G-21`/`G-23`/`G-24`/`G-25`/`G-26` use core `cron_schedules`/`wp_resource_hints`/`wp_speculation_rules` instead per `ADVERSARIAL G-23`/`G-25` REJECT.

---

## 6. Per-Area Recommendation Consolidated

| Area | Pick | Why adversarial |
|------|------|-----------------|
| Help (`@synopsis`) | **B** | `W-01` docblock zero-runtime fast fail |
| Output (`--format`) | **B** | `W-02` canonical — `Woo` `product list --format=json` even admin needs it |
| Dry-run | **B** | `W-04` retain cache+database, reject image |
| Multisite (`--network`) | **B** | `W-07` + `W-08` — doc `--url` + `get_sites` loop with guards |
| Cache veto | **B** | `G-01`/`G-28` P0 |
| Cache observability | **B** | `G-02` modify — `written`+`miss`, reject `hit` drop-in boot |
| Buffer stages | **A** | `G-04` reject |
| Preload caps | **B** | `G-05` narrowed — `batch+sitemap_limit+urls` |
| CDN / defer | **B** | `G-07` + `G-09` narrow |
| Image / lazy | **B** | `G-11`/`G-12`/`G-13` narrowed |
| DB per-type + tuning | **B** | `G-15` P0 + `G-16` narrowed |
| RUM / Object-cache / Settings / Purge | **B** | `G-17`/`G-19`/`G-20`/`G-27` narrowed; `G-18`/`G-21`/`G-23-26` use core |
| Progress / batch | **B** | `W-05`/`W-06` image+`all` only |

*Research-only. Matrices map to `OPTIONS-COMPARISON.md` scored P0-3.*
