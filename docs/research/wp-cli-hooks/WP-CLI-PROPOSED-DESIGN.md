# WP-CLI Proposed Design — Automation & Parity Spec

**Date:** 2026-08-28 · **Plugin:** `performance-optimisation` v1.9.0  
**Source file:** `includes/class-wppo-cli-command.php:1-973` 7 public `@subcommand` methods (registered `includes/class-main.php:472-474` class-as-collection `WP_CLI::add_command('wppo',Class)` at `class-main.php:472` handbook "Include in a plugin or theme")  
**Conventions:** `WP-CLI-RESEARCH.md` (16 handbook sections, §1-§16 Source URL → Relevant guidance), `ECOSYSTEM-RESEARCH.md §1.2` (11 recommendations), per-survivor `ADVERSARIAL-REVIEW.md §1` W-01—W-13 (`RETAIN`/`MODIFY`/`REJECT`), `PERF-RESEARCH.md`, `MIGRATION-COMPATIBILITY.md`, `TEST-PLAN.md`. No production edits — every proposal cites `file:line`.

> Rule: Do **not** blindly copy global `WP_CLI` options. Each `--flag` here is justified only if it unlocks scripting/CI or matches handbook standard (`WP_CLI::confirm`, `search-replace --dry-run`, `make_progress_bar`, `format_items`, `--url/--network`). Alias `wppo` is already 4-char short so no `wp wppo` → `wp performance-optimisation` alias (`WP-CLI-GAPS.md §4` keeps single `wppo` namespace until ≥10 families).

---

## 0. Global Design Principles (Applies to Every Subcommand)

| Principle | Handbook source | How applied |
|-----------|-----------------|-------------|
| **Synopsis correct** | `handbook/guides/commands-cookbook/#longdesc` → `<req>` vs `[<opt>]` vs `[--assoc=<val>]` vs `[--flag]`; `--- default: + options:` YAML validated before `invoke` (`WP-CLI-RESEARCH.md §2`) | All 7 docblocks currently say `<action>` (`:49,131,301,757,880`) but code defaults `args[0] ?? 'clear'/'cleanup'/'status'` (`:76,175,322,802,903`) — change to `[<action>]` + `--- default: clear + options: clear,preload,status ---` etc. per `ADVERSARIAL W-01 RETAIN` (docblock-only, zero runtime) |
| **`--format` / `--fields` / `--field`** | `handbook/references/internal-api/wp-cli-utils-format-items` + `Formatter` `table\|json\|csv\|yaml\|count\|ids` + `wp user list --fields …` (`WP-CLI-RESEARCH.md §6`) | Add to **every data command** — `cache status`, `database counts`, `image status`, `object-cache status`, `pagespeed results`, `system-info` — guard `class_exists('WP_CLI\Formatter')` fallback `wp_json_encode` (`MIGRATION C-WPCLI-03`), narrow `pagespeed results` to `table\|json` only (large diagnostics `csv` not sensible) per `ADVERSARIAL W-02 MODIFY` |
| **`--yes` / `--confirm` + `WP_CLI::confirm`** | `handbook/references/internal-api/wp-cli-confirm` (`WP_CLI::confirm($q,$assoc_args)` respects `--yes`) + Rocket `wp rocket clean --confirm` alias `ECOSYSTEM-RESEARCH.md §4.1.5` | Gate **destructive** `cache clear` (all), `database cleanup --type=all`, `object-cache disable`, `settings import` — check both `Utils\get_flag_value($assoc,'yes')` and `--confirm` alias (`MIGRATION C-WPCLI-05`) per `ADVERSARIAL W-03 RETAIN` |
| **`--dry-run`** | `developer.wordpress.org/cli/commands/search-replace --dry-run` + DeliciousBrains `START TRANSACTION / ROLLBACK` recipe (`WP-CLI-RESEARCH.md §8`) | Preview `database cleanup` per-type counts via `get_counts` at `class-database-cleanup.php:892-915` without `DELETE LIMIT 1000` `:138-180`; `cache clear` list files via `Cache::get_cache_stats:2184` without `unlink`; **reject** `image convert --dry-run` (no DB delete; `image status` already shows `total_pending:385`) per `ADVERSARIAL W-04 MODIFY` |
| **`make_progress_bar`** | `handbook/references/internal-api/wp-cli-utils-make-progress-bar` auto-`NoOp` when piped `Shell::isPiped()` (`WP-CLI-RESEARCH.md §7`) | `image convert` (≤100×0.2-1s) + `database cleanup --type=all` (9 serial cleaners `LIMIT 1000`) — **reject** `cache status` dir walk 200-800ms transient-hit + `database optimize` 5 tables per `ADVERSARIAL W-05 MODIFY` gate `count>10` before bar |
| **`--batch-size`** | DeliciousBrains `DB_Migration_Command --batch-size default 500 max(1,(int)…)` + per-batch `wp_cache_flush()` (`WP-CLI-RESEARCH.md §10`) | `image convert` override `batch 50` at `:330`, `database cleanup` override `1000` at `class-database-cleanup.php:138` — **reject** for `optimize`/`preload` per `ADVERSARIAL W-06 MODIFY` (small/non-amplifying) |
| **Multisite** | Global `--url=<url>` "In multisite, how target site is specified." + `wp user list --network` + Rocket `clean --blog_id` + LSCWP `blog <id>` (`WP-CLI-RESEARCH.md §11`, `ECOSYSTEM-RESEARCH.md §4.1.8`) | Document `In multisite use wp --url=<site> wppo …`; add `[--network]` paginated `get_sites(number 100 offset)` loop `uninstall.php:193-217` + `try{switch}finally{restore}` (`C-MULTI-07`) + per-blog `Util::clear_settings_cache()` :91-106; **reject** `--blog_id` CSV (duplicates `--network`) per `ADVERSARIAL W-07 MODIFY` |

**Exit codes** per `handbook/references/exit-codes` + `WP_CLI::success→0`, `error→1`, `warning→continue 0` (`WP-CLI-RESEARCH.md §5`): data-commands still `success/log` even empty list (exit 0); destructive blocked without `--yes` goes through `confirm` (interactive writes `fwrite(STDOUT)` so CI must pass `--yes`).

---

## 1. Per-Subcommand Spec (Namespace `wppo`, 7 Survivors — No New Subcommand per `W-11 REJECT`)

### 1.1 `wp wppo cache` (extends `class-wppo-cli-command.php:43-124`)

**Purpose** Static HTML cache (`wp-content/cache/wppo/{domain}/{path}/index.html` `class-cache.php:40-41` + gzip/br) via output buffer `class-main.php:545-550` + `advanced-cache.php` drop-in.

| Field | Spec |
|-------|------|
| **Namespace / Subcommand** | `wppo` / `cache` (`@subcommand cache` :70) — keep |
| **Arguments (positional)** | `[<action>]` (was `<action>`) — `clear\|preload\|status` optional defaults `clear` at `:76` — fix synopsis `--- default: clear + options: clear,preload,status ---` (W-01) |
| **Arguments (assoc)** | `[--page=<url>]` for `clear` only at `:52,99` (`wp_parse_url PATH` `wp_normalize_path` trim `/` `:102` → `Cache::clear_cache($path)` :103 fallback `..` reject inside service) |
| **Options (proposed)** | `[--yes]` (`--confirm` alias) for `clear` all — `WP_CLI::confirm('Delete all static HTML cache? [y/n]', $assoc_args)` unless `Utils\get_flag_value($assoc,'yes')\|\|get_flag_value($assoc,'confirm')` (W-03) <br> `[--dry-run]` for `clear` — skip `unlink`, `log` would-delete list from `Cache::get_cache_stats` dir walk `:86` (W-04 retain cache) <br> `[--format=<format>]` for `status` — `table\|json\|csv\|yaml\|count` via `Formatter` default `table` preserve 3 `log(sprintf…)` lines `:87-89` when omitted (W-02) <br> `[--network]` multisite sweep — `get_sites([...number 100 offset...])` loop with `switch/restore` (W-07) — do **not** add `--batch-size`/`--limit` for preload (W-06 reject) |
| **Permissions** | Any shell `wp` invoker (CLI has zero `current_user_can manage_options` check `class-rest.php:357-361` — REST only; `WP-CLI-CURRENT.md §2.1` trusted shell). Destructive gated by `confirm`+`--yes`, not capability |
| **Output** | `preload` → `WP_CLI::success('Cache preload initiated…')` :81 + `Log::add('Cache preload triggered via WP-CLI')` :80 <br> `status` → `Formatter display_items` (when `--format`) else legacy `log('Cache size: %s')` `:87-89` <br> `clear --page` → `success` :109 / `error` :112 <br> `clear` all → `success` :120 / `error` :122 |
| **Errors / exit codes** | Invalid action → `WP_CLI::error('Invalid cache action "%s"…')` :95 exit 1; page `Cache::clear_cache` false → `error` :112 exit 1; FS walk error → `error` — unchanged |
| **Batch / multisite** | `--network` paginated loop; per-blog `Util::clear_settings_cache()` + `Util::$home_url_cache` reset (`class-util.php:112,214`); warn `object-cache flush is all-sites` pattern at `templates/object-cache.php:532` not cache but keep note |
| **Examples** | Interactive: `wp wppo cache clear` (prompts `Delete all static HTML cache? [y/n]`) <br> Script: `wp wppo cache clear --yes` (CI `deploy:post-release`) <br> Dry preview: `wp wppo cache clear --dry-run --format=json \| jq .would_delete` <br> Single page: `wp wppo cache clear --page=/sample-page/ --yes` (`:52` example) <br> Status machine: `wp wppo cache status --format=json \| jq .cached_pages` <br> Multisite: `wp --url=https://sub.example.com wppo cache clear --page=/about/` / `wp wppo cache clear --network --yes` |

### 1.2 `wp wppo database` (`class-wppo-cli-command.php:126-294`)

**Purpose** Batched bloat removal `delete_in_batches` `class-database-cleanup.php:138-180` + `OPTIMIZE TABLE` `class-database-cleanup.php:1040-1088` + salted counts `get_counts:842-925`.

| Field | Spec |
|-------|------|
| **Namespace / Subcommand** | `wppo` / `database` (`@subcommand database` :169) — keep |
| **Arguments (positional)** | `[<action>]` → `cleanup\|optimize\|counts` defaults `cleanup` at `:175` — fix + `--- default: cleanup + options: cleanup,optimize,counts ---` (W-01) |
| **Arguments (assoc)** | Cleanup only `[--type=<type>]` default `all` at `:212` allowlist `revisions\|auto_drafts\|trashed_posts\|spam_comments\|trashed_comments\|expired_transients\|orphan_postmeta\|unattached_media\|oembed_cache\|all` plus aliases `drafts,trash,spam,trashed,transients,orphans,unattached,oembed` at `:237-277` aliased via `invoke_cleanup_method:935-942` `false→WP_Error` — keep <br> Optimize only `[--tables=<tables>]` default `posts,postmeta,comments,commentmeta,options` at `:178` allowlist `array_merge(...TABLE_MAP)` :180 `TABLE_MAP:42-52` + `CLEANUP_METHOD_MAP:81-91`; CSV `array_map(trim,explode(',',…))` :179 |
| **Options (proposed)** | `[--yes]` (`--confirm` alias) for `cleanup --type=all` — `confirm('Delete all bloat? …')` (W-03) <br> `[--dry-run]` for `cleanup` — skip `delete_in_batches` `:138`, report `get_counts()` preview counts (W-04 retain database) <br> `[--batch-size=<n>]` for `cleanup` — `max(1,(int)…)` override `1000` (W-06 retain database+image only) <br> `make_progress_bar` for `cleanup --type=all` 9 serial cleaners (W-05 retain `all`; reject for `optimize`/`counts`) <br> `[--format=<format>]` + `[--fields=<fields>]` + `[--field=<field>]` for `counts` — `table\|json\|csv\|yaml\|count` via `Formatter`/`format_items` default `table` (W-02) <br> `[--network]` sweep — same pattern as cache |
| **Permissions** | Shell trust; `--yes` gates `all` |
| **Output** | `optimize` per table `log - Optimized table: %s` :190 / `warning - Skipped unknown table: %s` :184 + `success Database optimization complete: %d/%d tables optimized.` :196 (fix denominator to allowlisted count) <br> `counts` → `Formatter` default `table` else JSON pretty :202 <br> `cleanup --type=all` per-type `log - %s: %d cleaned` :225 / `warning - %s: %s` :220 (WP_Error) + `success … (all): %d total` :231 <br> `cleanup --type=X` → `success … (%d items removed)` :293 fresh via `Log::add` :291 |
| **Errors / exit codes** | Invalid action `:208` `error` 1; invalid type `:275` `error` 1; per-type `is_wp_error` `warning` continue in `all` vs `error` :280 single-type; `false` `:284-286` `error` 1 |
| **Batch / multisite** | `--batch-size` passed to `invoke_cleanup_method` batch param at `class-database-cleanup.php:138`; `make_progress_bar` per type `tick` finish; `--network` loop per blog `clear_settings_cache` + `transient_key` prefix `class-util.php:781-790` |
| **Examples** | Script: `wp wppo database cleanup --type=all --yes` (nightly cron container) <br> Preview: `wp wppo database cleanup --type=revisions --dry-run --format=json \| jq .would_delete` <br> Batch tune: `wp wppo database cleanup --type=orphan_postmeta --batch-size=500 --yes` (50M `postmeta`) <br> Counts machine: `wp wppo database counts --format=table` / `wp wppo database counts --format=count` <br> Optimize: `wp wppo database optimize --tables=posts,postmeta --yes` <br> Multisite: `wp wppo database cleanup --type=all --network --dry-run` (per-blog preview without `DELETE`) |

### 1.3 `wp wppo image` (`class-wppo-cli-command.php:296-391`)

**Purpose** Next-gen queue `wppo_img_info` non-autoload option at `class-wppo-cli-command.php:328` + `Img_Converter:113-130` `core_handles_next_gen` gate.

| Field | Spec |
|-------|------|
| **Namespace / Subcommand** | `wppo` / `image` (`@subcommand image` :316) — keep |
| **Arguments (positional)** | `[<action>]` → `convert\|status` defaults `status` at `:322` — fix + `--- options: convert,status ---` |
| **Arguments (assoc)** | `[--format=<format>]` at `:323` default `''`→ settings `conversionFormat` `webp` at `:329` allowlist `'both'→['avif','webp']` else single `avif|webp` at `:335` invalid→empty `0/0` |
| **Options (proposed)** | `[--batch-size=<n>]` override `batch` 50 at `:330` `max(1,(int)…)` (W-06 retain image) <br> `make_progress_bar('Converting images', total_pending)` tick per `convert_image($source_path,$fmt)` :357 finish when `count>10` (W-05 retain image) <br> `[--format=<format>]` + `[--fields]`/`[--field]` for `status` via `Formatter` default `table` (W-02) — limit `status` to `table\|json` (large dims not csv) <br> **Do not** add `[--dry-run]` (W-04 reject image — `status` already `total_pending:385` preview) <br> **Do not** add `[--force]` re-encode `completed` (Yoast `index --reindex` analog `ECOSYSTEM-RESEARCH.md §4.2` — defer) |
| **Permissions** | Shell trust; `convert` writes `WP_CONTENT_DIR/wppo/…` via `Util::prepare_cache_dir` at `class-img-converter.php` |
| **Output** | `convert` → `success Image conversion complete: %d/%d images processed.` :365 + `Log::add` :363 (inner loop caps `counter>=batch_size` break :348-350, `realpath` + `ABSPATH` prefix :353-355 `continue`) <br> `status` → JSON `total_pending/total_completed/pending:{webp,avif}/completed:{webp,avif}` :385 → via `Formatter` default `table` |
| **Errors / exit codes** | Invalid action `:390` error 1; invalid format silent `0/0` (should warn `Unrecognized format` future) |
| **Batch / multisite** | Batch capped per format `50` (or `--batch-size`); each decode GD `imagecreatefrom*` → palette→truecolor + Imagick GIF→WebP at `class-img-converter.php:320-686` memory spike; future `Image_Service::convert_batch()` (`ARCH-RESEARCH.md §2-§3`) enables mock |
| **Examples** | Script: `wp wppo image convert --format=webp --batch-size=20` (VPS 512 MB) <br> All: `wp wppo image convert --format=both` → loops `avif`+`webp` :343-360 `4/4` if 2 each <br> Status: `wp wppo image status --format=json \| jq .total_pending` <br> Multisite: `wp --url=https://sub.example.com wppo image status` |

### 1.4 `wp wppo settings` (`class-wppo-cli-command.php:392-750` — 483 lines incl. helpers `451-522,864-872`)

**Purpose** Single source `wppo_settings` (`Util::get_settings:145-157` per-blog memo) mirror `Main:170-340` defaults backfills.

| Field | Spec |
|-------|------|
| **Namespace / Subcommand** | `wppo` / `settings` (`@subcommand settings` :567, two docblocks :393-441 + :524-572) — keep |
| **Arguments (positional)** | `[<action>]` → `get\|update\|export\|import` defaults `get` at `:574` — fix + `--- options: get,update,export,import ---` <br> `[<tab>]` optional at `:575` filtered `isset($options[$tab])` :680-682 else error `Invalid settings tab "%1$s". Available tabs: %2$s` |
| **Arguments (assoc)** | `get`: `[--format=<format>]` default `json` at `:690` options `json,yaml` Spyc/yaml_emit fallback `:693-698` warning fallback <br> `update`: `[--settings=<json>]` required at `:713` `json_decode+is_array` `:719-722` + sanitize `sanitize_settings_recursively:726` at `class-util.php:877-913` <br> `export`: `[--file=<path>]` optional at `:601` else stdout `log json` :617; strips `object_cache.password` + `performance_audit.pagespeed_api_key` :593-599 <br> `import`: `--file=<path>` required at `:623-625` `exists` :631 `get_contents`+`json_decode` :636-641 allowlist `ALLOWED_SETTINGS_KEYS:43-58` 13 keys :644-651 + sanitize :654 + strip `password_set:true` at `:661` + unset `pagespeed_api_key` :666 + `array_replace_recursive` merge `update_option` :671 |
| **Options (proposed)** | `[--format=<format>]` expand `get` to `Formatter` `table\|json\|csv\|yaml\|count\|ids` default `table` (W-02) + `[--fields]/[--field]` for tab filtering (LSCWP `litespeed-option all --format` pattern `ECOSYSTEM-RESEARCH.md §4.2`) <br> `[--yes]` (`--confirm` alias) + `confirm` for `import`/`update` destructive (W-03) <br> `[--dry-run]` for `import` + `update` — validate `json_decode` + allowlist + sanitize without `update_option` (W-04 retain settings import) <br> Fix `get_default_settings():451-522` drift — add 7 missing tabs `litespeed_integration,llms_txt,od_integration,bfcache,perf_translations,ai_adaptive,edge_cache` vs `Main:240-265` (W-09 RETAIN → `Settings_Service::defaults` single-source `ARCH-RESEARCH.md §2`) <br> `[--network]` for `export/import` matrix not needed — docs "per-blog `wp --url` loop or `--network --file=/tmp/combined.json` (array keyed by `blog_id`)" |
| **Permissions** | Shell trust; `--yes` gates overwrite |
| **Output** | `get json` → `log <json pretty>` :702 / yaml `:694,:696` fallback `warning` + json `:698-699` <br> `update` → `Log::add` + `success Settings updated successfully for tab "%s".` :744 (warning `Unrecognized tab` :731 still saves) <br> `export --file` → `success Settings exported to %s` :615 else `log` :617 <br> `import` → `success Settings imported successfully.` :674 |
| **Errors / exit codes** | `export` FS init fail `:606` error 1, write fail `:611` error 1, `import` missing file `:632` error 1, invalid JSON `:639,722` error 1, unknown `setting key "%s"` `:648` error 1, missing `tab` for `update` :709 error 1, missing `--settings` :715 error 1 |
| **Batch / multisite** | `--network` sweep would use same paginated `get_sites` loop but settings-per-blog semantics mean combined export is `array[blog_id => settings]` — defer until proven |
| **Examples** | Script: `wp wppo settings get file_optimisation --format=json \| jq .minifyHTML` <br> Update: `wp wppo settings update file_optimisation --settings='{"minifyHTML":true}' --yes` <br> Export file: `wp wppo settings export --file=/tmp/wppo-2026-08-28.json` (strip `object_cache.password` Redact `W-03`) <br> Import: `wp wppo settings import --file=/tmp/wppo-2026-08-28.json --yes` / `cat /tmp/out.json \| wp wppo settings import --file=/dev/stdin --yes` (Rock `import` pattern) <br> Dry: `wp wppo settings import --file=/tmp/candidate.json --dry-run --format=json \| jq .would_update` <br> Multisite: `wp --url=https://sub.example.com wppo settings get --format=json` / `./scripts/foreach-site.sh 'wp --url={{url}} wppo settings get preload_settings --format=json'` |

### 1.5 `wp wppo object-cache` (`class-wppo-cli-command.php:752-856` + helper `:864-872`)

**Purpose** Redis drop-in `wp-content/object-cache.php` + `wp-content/wppo-redis-config.php` (`class-object-cache.php:67` `wppo_object_cache_dropin_path` filter).

| Field | Spec |
|-------|------|
| **Namespace / Subcommand** | `wppo` / `object-cache` (`@subcommand object-cache` :796 method `object_cache` dash→underscore) — keep |
| **Arguments (positional)** | `[<action>]` → `status\|ping\|enable\|disable\|flush` defaults `status` at `:802` — fix |
| **Arguments (assoc)** | `[--host]`/`[--port]`/`[--password]`/`[--database]`/`[--timeout]`/`[--prefix]` at `:866-870` `get_redis_config_from_assoc` — currently **6 keys only** vs REST 10 (`mode,nodes,master_name,use_tls,persistent,compression` at `class-rest.php:1104-1142`) — expand to 10 + `wppo_cli_redis_config` filter return at `:864` (`W-10` RETAIN, `G-19` retain) |
| **Options (proposed)** | `[--yes]` for `disable`/`enable` (W-03) <br> `[--format=<format>]` for `status` `table\|json\|csv\|yaml\|count` via `Formatter` default `table` (W-02) — currently JSON status blob `:808` `enabled,redis_missing,redis_reachable,foreign_dropin,telemetry` <br> `[--network]` not needed — `wp_cache_flush()` flush is all-sites (`templates/object-cache.php:532` `object_cache_allow_flush_all` + `PERF-RESEARCH.md §2.4` note) so CLI must `warning('flush is all-sites; prefer --url for single')` per `ADVERSARIAL W-07` |
| **Permissions** | Shell trust; `enable/disable` writes `wp-content/*.php` needs FS `is_writable` not WP cap (`class-object-cache.php:252-316` `ping` before write, `wppo_parse_nodes`, `put_contents`/`copy`/`wp_cache_flush`) |
| **Output** | `status` `log JSON pretty` :808 <br> `ping` `success Redis server is reachable.` :817 else `error` WP_Error :815 <br> `enable` `Log::add` :827 + `success … enabled` :828 else `error foreign_dropin/redis_unreachable/write_error` :825 <br> `disable` analogous :837-838 <br> `flush` `Log::add` :845 + `success` :846 else `error` :848 |
| **Errors / exit codes** | Invalid action :854 error 1; missing extension `redis` → WP_Error; foreign drop-in → error |
| **Examples** | Script: `wp wppo object-cache ping --host=127.0.0.1 --port=6379` (deploy probe) <br> Enable cluster: `wp wppo object-cache enable --mode=cluster --nodes=redis-1:6379,redis-2:6379 --use_tls=true --compression=zstd --yes` (10-key allowlist) <br> CI: `wp wppo object-cache status --format=json \| jq .redis_reachable` |

### 1.6 `wp wppo pagespeed` (`class-wppo-cli-command.php:874-932`)

**Purpose** Async Action Scheduler `as_enqueue_async_action('wppo_pagespeed_scan')` `class-pagespeed.php:119-133` hook `performance_optimisation` + transient TTL `DAY_IN_SECONDS` at `:64`.

| Field | Spec |
|-------|------|
| **Namespace / Subcommand** | `wppo` / `pagespeed` (`@subcommand pagespeed` :897) — keep |
| **Arguments (positional)** | `[<action>]` → `scan\|results` defaults `scan` at `:903` — fix |
| **Arguments (assoc)** | `[--url=<url>]` default `Util::cached_home_url()` :904 (`wp_http_validate_url`/`esc_url_raw` inside `queue_scan`) <br> `[--strategy=<strategy>]` default `mobile` at `:905` not validated except downstream |
| **Options (proposed)** | `[--format=<format>]` for `results` — `table\|json` only (W-02 MODIFY — reject `csv` for large diagnostics `lighthouseResult`) <br> No `--dry-run`/`--yes` (scan is `as_enqueue_async_action` enqueue, not destructive DB) |
| **Permissions** | Shell trust; `scan` enqueues via `Pagespeed::queue_scan:908` returns job ID `int>0` else `error Action Scheduler may be unavailable` :910 1 |
| **Output** | `scan` → `Log::add Pagespeed scan queued via WP-CLI for %s` :914 + `success Pagespeed scan queued. Job ID: %d` :916 <br> `results` → `warning No PageSpeed results found…` :923 exit 0 continue (W-02 `is-installed` binary 1 vs warning 0 debate — keep warning) else `log JSON pretty` :926 |
| **Errors / exit codes** | Invalid action :931 error 1; scan queue `<=0` error 1 |
| **Examples** | Script: `wp wppo pagespeed scan --url=https://example.com --strategy=mobile` (enqueue) <br> Results: `wp wppo pagespeed results --url=https://example.com --strategy=mobile --format=json \| jq .lighthouseResult.categories.performance.score` |

### 1.7 `wp wppo system-info` (`class-wppo-cli-command.php:934-972`)

**Purpose** `System_Info::get_all():65-77` groups `php,database,wordpress,wp_constants,server,cache,infrastructure` + `litespeed,opcache`.

| Field | Spec |
|-------|------|
| **Namespace / Subcommand** | `wppo` / `system-info` (`@subcommand system-info` :952 method `system_info`) — keep |
| **Arguments (positional)** | `[<group>]` optional at `:958` `php,database,…` validated `isset($all[$group])` :961 else `error Invalid system info group…` :962 — keep |
| **Arguments (assoc)** | None today |
| **Options (proposed)** | `[--format=<format>]` `table\|json\|csv\|yaml\|count\|ids` + `[--fields=<fields>]` + `[--field=<field>]` for data shape (W-02 retain — like `wp user list --fields=name,public`) — default `table` when `--format` omitted, `json` when `group` omitted? keep JSON pretty but via Formatter when flag present |
| **Permissions** | Shell trust; reads only (`System_Info::get_all` does `ini_get`, `$wpdb` `SHOW TABLE STATUS` fallback, `opcache_get_status` etc.) |
| **Output** | `isset($all[$group])` → `log JSON` single group :966 else all :970 |
| **Errors / exit codes** | Unknown `group` :962 error 1 |
| **Examples** | Interactive: `wp wppo system-info` (all JSON pretty today) <br> Script: `wp wppo system-info php --format=json \| jq .php` <br> Machine: `wp wppo system-info --format=table --fields=php,server,cache` / `wp wppo system-info --field=php --format=json` |

---

## 2. Gaps Not Promoted to New Subcommand (Per `ADVERSARIAL W-11 REJECT`, Document Instead)

Per `W-11` handbook cookbook "for any admin action there should be equivalent CLI" is **aspirational, not required** — primary CI use-cases already covered (`cache clear`, `database cleanup`, `settings import`, `object-cache flush`).

| Rejected subcommand | REST/UI source | Why rejected | Operator fallback (document in `wp wppo --help` + `docs/hooks.md`) |
|---|---|---|---|
| `rum {collect\|flush\|rum_data}` | `rum_collect` public `__return_true` :227 + `RUM::collect:121` / `RUM::flush_queue:352` `wppo_rum_flush:74` | Duplicates `wppo_rum_flush` cron; sampling via filter `wppo_rum_should_collect:G-17` retained | `RUM data via REST rum_data / web_vitals_trends; scripting: wp option get wppo_web_vitals_trends --format=json` |
| `activities` | `recent_activities` `class-log.php` + `GET v1/recent_activities` | Single `GET` observation, derived | `wp db query "SELECT * FROM {prefix}wppo_activity_logs ORDER BY created_at DESC LIMIT 20"` |
| `autoloaded_options` | `GET v1/autoloaded_options` :236-241 `get_autoloaded_options()` | Audit only, not mutate | `wp option list --autoload=yes --format=table` or `wp wppo system-info wp_constants --format=json` |
| `used_css` / `ccss` `regenerate` / `ccss_status` | `POST v1/used_css_regenerate` + `regenerate_ccss` + `ccss_status` via `wppo_used_css_generate:781` 5-hourly cron :72-73 | Cron-internal; operator waits for `every_5_hours` `:99` or REST | `wp cron event run wppo_used_css_cron` + `wp option get wppo_ccss_status` |
| `cron {health}` | `Cron::schedule_cron_jobs:114` `every_5_hours` | Core `cron_schedules:61` already filter; WP-CLI `cron event list` exists | `wp cron event list --format=table \| grep wppo` |
| `suggestions` / `web_vitals_trends` | `GET v1/suggestions` → `Suggestion_Engine` + `record_trend` capped 30 | Derived from telemetry; no CI value | `wp option get wppo_web_vitals_trends --format=json` |
| `server_rules` | `GET v1/server_rules` 180-184 → `class-server-rules.php` | Nginx/Apache snippet, no FS | `wp wppo system-info server --format=json` + `cat .htaccess` |
| `ai_*` / `llms` / `edge` / `health` / `benchmark` / `preload` / `used_css` separate families | `class-ai-adaptive.php:60` etc., LiteSpeed 8 families `docs.litespeedtech.com/lscache/lscwp/cli/` | Architecture `G-21—G-26` filters deferred; `W-11` keeps `WPPO_CLI_Command:973` from 2k lines | Document absence — defer until ≥10 families or proven operator request |
| `cache purge` alias `clear`, `settings preset apply/list`, `reset --yes`, `image convert --force`, `import_remote <URL>` | LiteSpeed `litespeed-option presets` + `import_remote` pattern `ECOSYSTEM-RESEARCH.md §4.2` | `@alias` not handbook official + presets storage `wppo_settings_backup_*` not yet (`ARCH-RESEARCH.md §7` future) (W-12 REJECT) | `wp wppo cache clear` canonical; presets `curl -o /tmp/p.json <URL> && wp wppo settings import --file=/tmp/p.json --yes` |

---

## 3. Global Flags Decision Table (Justified Per Option)

| Flag | Justify? | Where applied | Source |
|---|---|---|---|
| ` --yes` + ` --confirm` alias | Yes — destructive gate; `confirm` writes `fwrite(STDOUT)` reads `STDIN`, CI must pass `--yes` | `cache clear` all, `database cleanup --type=all`, `object-cache disable`, `settings import`/`update` | `W-03` + `WP_CLI::confirm($q,$assoc_args)` + `MIGRATION C-WPCLI-05` both-check `Utils\get_flag_value` |
| ` --dry-run` | Yes for mutations that `DELETE`/`unlink`/`update_option` | `database cleanup` (preview `get_counts`), `cache clear` (list files), `settings import`/`update` (validate without `update_option`) — **No** for `image convert` (`status` already preview `:385`) | `W-04` MODIFY + `developer.wordpress.org/cli/commands/search-replace --dry-run` |
| ` --format=<format>` | Yes — data commands need `jq`-parseable | `cache status`, `database counts`, `image status`, `object-cache status`, `pagespeed results` (narrow `table\|json`), `system-info`, `settings get` (expand to `table\|json\|csv\|yaml\|count`) | `W-02` + `Formatter`/`format_items` auto-`NoOp` when piped not needed |
| ` --fields=<fields>` / ` --field=<field>` | Yes — field filtering (`wp user list --fields=…`) | `system-info` + `settings get` + `database counts` via `Formatter` | Handbook `argument-syntax --field` |
| ` --batch-size=<n>` | Yes only where batch is inner loop amplification | `image convert` (decode 0.2-1s) + `database cleanup` (50M `postmeta`) `max(1,(int)…)` per DeliciousBrains | `W-06` MODIFY |
| ` --network` | Yes — `switch_to_blog` isolation; doc `wp --url` always primary | `cache clear`, `database cleanup`, `settings import` — **not** `object-cache flush` (all-sites warn) | `W-07` + `uninstall.php:193-217` pagination |
| ` --url` global | Document only — WP-CLI Runner already sets before `after_wp_load` | Help note "In multisite, use `wp --url=<site> wppo …`" | `WP-CLI-RESEARCH.md §11` |
| ` --verbose` / ` --quiet` | No — use core globals `--debug`/`--quiet`/`--prompt` (WPPO `log/success/warning/error` already quiet-aware `WP-CLI-RESEARCH.md §9`) | Do not add per-command `[--verbose]`; use `WP_CLI::debug('Skipping '.$source_path,'wppo')` visibility with `--debug=wppo` | `MIGRATION C-WPCLI-01` |
| ` --force` / ` --field` already | Defer `--force` re-encode `completed` until proven; `--field` above is sub-case | — | Yoast `index --reindex` defer |

---

## 4. Output & Error Contract

| Command | Success format | Error format | Exit 0 | Exit 1 |
|---|---|---|---|---|
| `cache *` | `WP_CLI::success('Static HTML cache cleared successfully.')` :120 or `Cache cleared for page: %s` :109 | `WP_CLI::error('Failed to clear…')` :112/:122 | success/warning | invalid action `:95` |
| `database *` | `log - Optimized table: %s` :190 / `success Database cleanup completed (%1$s): %2d total` :231,:293 | `error Invalid cleanup type …` :275, `is_wp_error→error` :280, `false→error` :286 | `warning - Skipped unknown table: %s` :184 continues 0 | invalid action :208 |
| `image *` | `success Image conversion complete: %d/%d` :365 | `error Invalid image action…` :390 | — | 1 |
| `settings *` | `success Settings updated…` :744 / `Settings exported to %s` :615 | `error Unable to initialize filesystem.` :606, `Failed to write…` :611, `File not found…` :632, `Invalid JSON…` :639,:722 | `warning Unrecognized tab…` :731 continues 0 | invalid action :749, tab :682 |
| `object-cache *` | `log JSON status` :808 / `success Redis server is reachable.` :817 | `error` WP_Error :815,:825,:835, `error Invalid …` :854 | — | 1 |
| `pagespeed *` | `success PageSpeed scan queued. Job ID: %d` :916 | `error Failed to queue…` :910 | `warning No PageSpeed results…` :923 0 (binary-not-found stays warning per `W-02` debate) | invalid action :931 |
| `system-info` | `log JSON` :966,:970 | `error Invalid system info group…` :962 1 | — | 1 |

---

## 5. Batch / Multisite / Performance Notes

- **Batch** loops: `image convert` per `fmt` at `:343-360` caps `counter>=batch_size` break `:348-350`; `database cleanup` `delete_in_batches LIMIT 1000` at `class-database-cleanup.php:138-180` loops until `<batch`; both respect `--batch-size` + `make_progress_bar tick/finish` with `count>10` gate (`TEST-PLAN.md I6`, `PERF-RESEARCH.md §3.2` 200-800ms walk not barred).
- **Multisite** loop spec per `MIGRATION C-MULTI-06/07`: `function_exists('get_sites')` WP 4.6+ safe vs 6.2 floor, `is_multisite() && function_exists('is_multisite') && is_multisite()` try/catch at `class-util.php:788`, `get_sites(['number'=>100,'offset'=>…])` paginated (not unbounded OOM 10k sites), `try{switch_to_blog($id)}finally{restore_current_blog()}`, per-blog `Util::clear_settings_cache()` + `Util::$home_url_cache` reset `:112,214`.
- **Perms** none beyond shell trust per `WP-CLI-CURRENT.md §2.1`.
- **Alias** `wp wppo` short — no `wp performance-optimisation` alias; adding would need `WP_CLI::add_command('performance-optimisation',…)` second registration + doc duplication — defer per `W-12`.

---

*Research-only, no production edits. Maps to `WP-CLI-GAPS.md` (categories/status/diagnostics/config/cache/etc. + priority table) + `HOOK-PROPOSED-DESIGN.md` (filters `wppo_should_cache_request`, `wppo_cache_written`, `wppo_invalidation_urls`, `wppo_preload_*`, `wppo_cdn_url`, `wppo_settings_before_update`, `wppo_rum_*` etc.) + `BRAINSTORM.md`/`OPTIONS-COMPARISON.md` with scored P0-3.*
