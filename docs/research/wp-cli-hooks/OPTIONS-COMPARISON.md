# Options Comparison — Scored Consolidation of `BRAINSTORM.md` (A/B/C)

**Date:** 2026-08-28 · **Plugin:** `performance-optimisation` v1.9.0  
**Source:** `BRAINSTORM.md` per-area A minimal / B recommended / C long-term compared via `Complexity`/`Perf`/`Compat`/`DX`/`Testing`/`Migration`/`Future`/`Risk` → scores `Impact 1-5` / `Effort 1-5` / `Risk 1-5` → weighted priority `P0-3` (`HOOK-GAPS.md §0`).  
**Policy:** Survivor picks per `ADVERSARIAL-REVIEW.md §1-§3` (`RETAIN`/`MODIFY`/`REJECT`). All entries cite `file:line` + handbook URL. Research-only.

---

## 0. Scoring Method

| Metric | 1 | 2 | 3 | 4 | 5 |
|--------|---|---|---|---|---|
| **Impact** (user/perf/dev future) | nicety | convenience | useful | high-value | blocks automation/fork (P0) |
| **Effort** (lines + migration + docs) | 1-5 lines doc | 10-30 lines filter | 40-80 lines + Formatter/bar | 100-200 lines + service | 300+ refactor + PSR-4 |
| **Risk** (compat/perf regression) | `none` additive | `low` per-tag filter | `low` per-request×N | `medium` drop-in/switch_to_blog | `high` big-bang container |

**Weighted priority** (consistent with `WP-CLI-GAPS.md §0` + `HOOK-PROPOSED-DESIGN.md §2`):

- `P0` = `Impact 4-5 && Risk ≤2` **or** blocks fork/CI (`G-01`/`G-15`/`G-28`, `database cleanup --dry-run` for nightly deploy)
- `P1` = `Impact 3-4 && Risk ≤2` high-value ecosystem (`wppo_cache_written`, `wppo_invalidation_urls`, `wppo_cdn_url`, `--format`/`--yes`/`make_progress_bar`)
- `P2` = `Impact 2-3` useful tuning/observability large-site (`wppo_db_batch_size`, `wppo_preload_batch_size` VPS smaller caps)
- `P3` = `Impact 1-2` nice-to-have alias/sugar (`purge` alias `clear`, `presets apply`, `litespeed alias`)

**Color:** `B` column is the survivor pick per `BRAINSTORM.md §6` (all areas pick **B** except `buffer_stage` picks **A**). Scores shown are for the **B** (or **A**) survivor, not rejected `C`.

---

## 1. Consolidated Comparison Table (B survivor per area — except where noted)

| # | Area / Improvement (`BRAINSTORM.md` §) | Survivor | Gap IDs | Impact | Effort | Risk | Perf benefit | Dev benefit | User benefit | Weighted | Priority |
|---|----------------------------------------|----------|---------|--------|--------|------|--------------|-------------|--------------|----------|----------|
| 1 | **Help** `@synopsis` `[<action>]` + `--- default+options ---` + `In multisite … --url` (`MIGRATION C-WPCLI-02`) | **B** | — (`W-01` RETAIN) | 3 | 1 | 1 | none | typo fails before `Cache::clear_cache:103` | `wp help wppo cache` honest (`cache:49,131,301,757,880` → `[<action>]`) | High | **P1** |
| 2 | **Output** `--format`/`--fields`/`--field` + `Formatter`/`format_items` `table\|json\|csv\|yaml\|count` + guard `class_exists Formatter` fallback (`MIGRATION C-WPCLI-03`) | **B** | — (`W-02` RETAIN MODIFY — narrow `pagespeed` to `table\|json`) | 4 | 2 | 1 | none | scripting `\| jq` via `Utils\format_items` 0.23+ (`WP-CLI-RESEARCH.md §6`) | `wppo cache status --format=json` human `table` preserve | High | **P1** |
| 3 | **Dry-run** `[--dry-run]` preview counts without `DELETE`/`unlink`/`update_option` | **B** | — (`W-04` RETAIN MODIFY — `cache`+`database`+`settings import`, **reject** `image`) | 4 | 2 | 1 | none — skips `delete_in_batches LIMIT 1000 :138` / `unlink` / `OPTIMIZE` | `get_counts :892-915` + `get_cache_stats :2184` reuse (`TEST-PLAN.md D13`) | safe nightly `database cleanup --type=all --dry-run --format=json` | High | **P1** |
| 4 | **Multisite** doc `wp --url` + `[--network]` paginated `get_sites(number 100 offset)` `uninstall.php:193-217` + `try{switch}finally{restore}` + `warn flush is all-sites` | **B** | — (`W-07` RETAIN MODIFY — **reject** `--blog_id` CSV) | 4 | 2 | 2 | none | `Util::clear_settings_cache:91-106` per blog | `wppo cache clear --network --yes` atomic all-sites; `wp --url` already `WP_CLI\Runner::set_url_params` | High | **P1** |
| 5 | **Destructive guard** `WP_CLI::confirm + [--yes] (--confirm alias)` | **B** | — (`W-03` RETAIN) | 5 | 1 | 1 | none | `WP_CLI::confirm($q,$assoc_args)` `fwrite STDOUT` CI must `--yes` | `cache clear` all + `database cleanup --type=all` + `object-cache disable` + `settings import` (`class-wppo-cli-command.php:117,215,833,671`) | High | **P0/P1** |
| 6 | **Progress** `make_progress_bar` `tick/finish` auto-`NoOp` when piped `Shell::isPiped()` | **B** | — (`W-05` MODIFY — `image` ≤100 + `database --type=all` 9 serial, **reject** `cache status` walk + `optimize` 5) | 3 | 1 | 1 | none | gate `count>10` before bar (`WP-CLI-RESEARCH.md §7`) | `image convert` no longer "hung" 50-100s | Med | **P1** |
| 7 | **Batch knob** `[--batch-size=<n>]` `max(1,(int)…)` | **B** | — (`W-06` MODIFY — `image batch 50 :330` + `database 1000 :138`, **reject** `optimize`/`preload`) | 3 | 1 | 1 | low — `postmeta` 50M avoids `lock_wait_timeout` | DeliciousBrains recipe `WP-CLI-RESEARCH.md §10` | `database cleanup --batch-size=500` VPS tuning | Med | **P1** |
| 8 | **Fix `get_default_settings` drift** add 7 tabs `litespeed_integration` etc. vs `Main:240-265` | **B** | — (`W-09` RETAIN via `Settings_Service::defaults()` single-source `ARCH-RESEARCH.md §2`) | 3 | 1 | 1 | none | single source `Util::get_settings` `ALLOWED_SETTINGS_KEYS:43` | `Unrecognized tab :731` gone | Med | **P1** |
| 9 | **Fix `object-cache` allowlist** 6→10 `mode,nodes,master_name,use_tls,persistent,compression` + `wppo_cli_redis_config` filter `:864` | **B** | — (`W-10` RETAIN + `G-19` retain `object_cache_config` reject actions) | 4 | 1 | 1 | none | cluster `mode cluster` via CLI | `wppo object-cache enable --mode=cluster --nodes=…` works | High | **P1** |
| 10 | **Cache veto** `wppo_should_cache_request` `:1505 is_not_cacheable` + `:1755 maybe_store_cache` | **B** | G-01 (P0 RETAIN) | **5** | 1 | 1 | `none` — front-end only | blocks fork (consent/geo/membership) | per-URL consent `pmpro_hasMembershipLevel` | Very high | **P0** |
| 11 | **Cache optimize gate** `wppo_should_optimise_for_user` `:369 should_optimise_for_logged_in` + `:297 is_cache_allowed_for_current_user` | **B** | G-28 (P0 RETAIN) | 4 | 1 | 1 | `none` | `shop_manager` sees uncached cart | per-role caching | Very high | **P0** |
| 12 | **Cache observability** `wppo_cache_written` `:1741` + `wppo_cache_miss` `:1661` (**reject** `hit` drop-in) | **B** | G-02 (P1 MODIFY) | 4 | 1 | 1 | `none` — generation only | APM Prometheus `wppo_cache_written` + header `X-WPPO-Cache: HIT` for hit | hit-rate dashboard | High | **P1** |
| 13 | **Buffer stages** `wppo_buffer_stage` 6 stages `:1243 process_buffer_only` | **A** minimal (keep single `wppo_cache_page_html:1661`) | G-04 (P1 **REJECT**) | 2 | 3 | 2 | `low ×6` 0.2-0.6ms on 40 scripts | copies `wppo_cache_page_html` + `script_loader_tag:10 :515` | deferred | Low | **P3** (defer) |
| 14 | **Cache invalidation** `wppo_invalidation_urls` `:1838` + `cache_invalidated` per-file + `should_invalidate_on_save` `:552` | **B** | G-03 (P1 RETAIN) | 4 | 1 | 1 | `low` — `save_post` only | headless `/store-locator/{city}` CPT without `clear all` | smart purge extend | High | **P1** |
| 15 | **Preload tuning** `wppo_preload_batch_size:301` (200) + `sitemap_limit:364` (500) + `sitemap_urls:487` (**reject** `deadline:496` + `should_preload_page:283`) | **B** | G-05 (P1/P2 MODIFY) | 3 | 1 | 1 | `none` — cron 5-hourly | large catalog 10k products + headless `sitemap-custom.xml` | batch tuning | Med | **P1** |
| 16 | **CDN per-asset** `wppo_cdn_url` + `should_rewrite` `:1357,1382` (dual R2+Bunny) + `wppo_delay_should_delay` `:515,722` (strategy) — **reject** `defer`/`minify`/`combine` | **B** | G-07 (P1 RETAIN) + G-09 (P2 MODIFY retain `delay` only) | 4 | 2 | 1 | `low` per asset `has_filter` short-circuit | `private/*` veto + `checkout.js never delayed` | dual CDN | High | **P1** |
| 17 | **Combine** `wppo_combine_css_should_combine:649` per-handle | **A** docs-only defer | G-06 (P2 MODIFY docs-only) | 1 | 1 | 1 | `low` per style `PHP_INT_MAX` | `excludeCombineCSS` + `wppo_skip_combine:1143` cover | deferred | Low | **P3** |
| 18 | **Image** `wppo_should_convert_image:319` + `conversion_quality:377` + `image_converted:357` + `should_serve:887` (veto only) + `lazy_should_lazyload:~1400` (veto only) | **B** | G-11 (P1/P2 RETAIN) + G-12 (P2 MODIFY) + G-13 (P1 RETAIN) | 3 | 2 | 1 | `none`/`low` per image | photography 90 hero/70 thumbnail + Unsplash veto + Woo 2 eager | richer than `excludeConvertImages` / `excludeFirstImages` | High | **P1** |
| 19 | **DB per-type** `wppo_before_database_cleanup` + `type_completed` `:714,935` (keep `completed:737` for `all`) | **B** | G-15 (P0 RETAIN) | **5** | 1 | 1 | `none` — REST/CLI/cron | metrics/Slack per-type `database cleanup --type=revisions` | per-type observers | Very high | **P0** |
| 20 | **DB tuning** `wppo_db_batch_size:138` + `revision_defaults:753` (**reject** `optimize_max_bytes:1040`) | **B** | G-16 (P2 MODIFY) | 2 | 1 | 1 | `none` | `postmeta` 50M small batch + legal-hold `keep 20` | large sites | Med | **P2** |
| 21 | **RUM sampling** `wppo_rum_should_collect:121` + `sample_rate:189` + `collect_args:121` + `rate_limit:275` (**reject** `max_days/paths/queue` G-18) | **B** | G-17 (P1 RETAIN) / G-18 (P2 **REJECT**) | 3 | 1 | 1 | `none` — early return saves `update_option` writes | 1M PV/day 1% sampling via filter, privacy DNT | bounded `wppo_web_vitals_rum` | Med | **P1** |
| 22 | **Object-cache lifecycle** `wppo_object_cache_config:252` + `wppo_cli_redis_config:864` (**reject** `before_enable/disable/flushed` actions + `should_run/after_command` G-22) | **B** | G-19 (P1 MODIFY) + G-22 (P1/P2 MODIFY) | 4 | 1 | 1 | `none` — admin/CLI | `mode sentinel/cluster` without `redis-connect-helper.php` patch | `W-10` fix cluster | High | **P1** |
| 23 | **Settings veto** `wppo_settings_before_update:464` veto `false→WP_Error 400` (**reject** `sanitize`+`after_update`) | **B** | G-20 (P1 MODIFY) | 3 | 1 | 1 | `none` — REST/CLI `update`/`import` `:671,739` | policy `cdnURL` deny vs core `pre_update_option_wppo_settings` | enterprise | High | **P1** |
| 24 | **CDN/Edge purge targeting** `wppo_purge_urls:193,212` `purge_all` before HTTP purge (**reject** duplicate `should_purge`) | **B** | G-27 (P1 MODIFY) | 3 | 1 | 1 | `none` — after `wppo_after_cache_clear:2074` `class-main.php:623,626` | `absolute CDN URLs` vs `G-03` relative `invalidation_urls` | per-URL purge | Med | **P1** |
| 25 | **REST extensibility** `wppo_rest_routes` + `permission` + `pre_dispatch` `:58,357` | **A** docs-only (**reject** — use core `rest_pre_dispatch`) | G-21 (P1 **REJECT**) | 1 | 1 | 3 (security) | `none` — `wppo_rest_routes` could remove `rum_collect __return_true :227` | agency `custom/v1` namespace | agency adds own route | Low | **P3** |
| 26 | **Cron schedules** `wppo_cron_schedules:99` alias `cron_schedules:61` | **A** docs-only (**reject** — use core `cron_schedules`) | G-23 (P2 **REJECT**) | 1 | 1 | 1 | `none` — `every_5_hours 5*3600 :99` | `add_filter('cron_schedules',fn($s)=>…)` | single knob `wppo_cron_discovery_limit:666` kept | Low | **P3** |
| 27 | **Admin/frontend** `wppo_admin_localize_data` + `should_enqueue:494 ~2200` / `preload_links` + `speculation_rules:758-760` / `litespeed cache_control_header:958` / `block_assets_should_load_separate:821` etc. | **A** docs-only (**reject** — use core `wp_resource_hints:460-465` + `wp_speculation_rules:476` + `should_load_separate_core_block_assets:833`) | G-24/P2 G-25/P2 G-26/P3 G-29/P3 G-30/P3 G-31/P2 G-32/P2 REJECT | 1 | 1 | 1 | `low` extra per `admin_enqueue_scripts` all pages | white-label via `wp_add_inline_script` | `core should_load_separate_core_block_assets` | Low | **P3** |

---

## 2. Prioritization Roll-Up (P0-3 derived from above `Weighted` + risk gate)

| Priority | Items (from §1) | Size (codes) | Perf/perf-cost gate |
|----------|------------------|--------------|---------------------|
| **P0** (blocks fork/CI — ship PR-A) | 5, 10, 11, 19 (confirm + `should_cache` P0 + `should_optimise` P0 + per-type DB `P0`) | 4 hooks + 1 confirm wrapper (~10 lines) | `none` — additive filter/action, default `true` |
| **P1** (high-value ecosystem — ship PR-B) | 1, 2, 3, 4, 6, 7, 8, 9, 12, 14, 15, 16, 18, 21, 22, 23, 24 | 17 improvements (~120 lines `HOOK-PROPOSED-DESIGN.md §1`) | `none` vs `low` per-asset only when extension present (`has_filter`) |
| **P2** (useful large-site — ship PR-C) | 20 (DB tuning 2 filters) | 2 filters | `none` — cleanup/cron only |
| **P3** (nice-to-have/alias — defer PR-D) | 13, 17, 25, 26, 27 (buffer 6×, combine, REST, cron, admin/frontend) | 0 now — docs | `low ×N` not worth until Tailwind order case proven |

---

## 3. Cross-Table: Improvement → Hook IDs → File:Line

| Improvement | Hook(s) / CLI flag | File:Line Before/During/After | Priority |
|---|---|---|---|
| `should_cache_request` | filter `wppo_should_cache_request` | `class-cache.php:1505` entry `is_not_cacheable` + `:1755` `maybe_store_cache` **Before** write | P0 |
| `should_optimise_for_user` | filter `wppo_should_optimise_for_user` | `class-main.php:369` + `class-cache.php:297` **Before** `set_role_hash_cookie:495` / buffer `545-546` | P0 |
| `cache_written/miss` | actions `wppo_cache_written` `:1741` **After** `save_processed_buffer` success + `wppo_cache_miss` `:1661` **During** early return | `class-cache.php:1661,1741` | P1 |
| `invalidation_urls` | filter `wppo_invalidation_urls` `:1838` + action `wppo_cache_invalidated` per-file + `wppo_should_invalidate_on_save` `:552` | `class-cache.php:1838` + `class-main.php:552` | P1 |
| `preload_batch/limit/urls` | filters `wppo_preload_batch_size:301` + `wppo_sitemap_preload_limit:364` + `wppo_preload_sitemap_urls:487` | `class-cron.php:301,364,487` cron 5-hourly | P1 |
| `cdn_url/should_rewrite` | filters `wppo_cdn_url` + `wppo_cdn_should_rewrite:1382` | `class-cache.php:1357 maybe_apply_cdn` + `:1382 TagProcessor loop` per asset | P1 |
| `image convert/serve/lazy` | filters `wppo_should_convert_image:319` + `conversion_quality:377` + `wppo_next_gen_should_serve:887` + `wppo_lazy_should_lazyload:~1400` | `class-img-converter.php:319,377` + `class-image-optimisation.php:887,1400` | P1 |
| `db per-type` | actions `wppo_before_database_cleanup` + `type_completed` at `:714,935` | `class-database-cleanup.php:714,737,935` + `class-wppo-cli-command.php:237-277` | P0 |
| `db_batch/revision_defaults` | filters `wppo_db_batch_size:138` + `wppo_db_revision_defaults:753` | `class-database-cleanup.php:138,753` | P2 |
| `rum sampling` | filters `wppo_rum_should_collect:121` + `sample_rate:189` + `collect_args:121` + `rate_limit:275` | `class-rum.php:121,189,275` | P1 |
| `object_cache_config` | filters `wppo_object_cache_config:252` + `wppo_cli_redis_config:864` | `class-object-cache.php:252` + `class-wppo-cli-command.php:864` | P1 |
| `settings_before_update` | filter `wppo_settings_before_update:464` veto | `class-rest.php:464` + `class-wppo-cli-command.php:573,671,739` | P1 |
| `purge_urls` | filter `wppo_purge_urls` `:193,212` | `class-cdn-purger.php:193` + `class-edge-purger.php:212` after `wppo_after_cache_clear:2074` | P1 |
| CLI cross-cutting | `--format` Formatter `table\|json…` + `--yes/--confirm` `WP_CLI::confirm` + `--dry-run` + `make_progress_bar>10` + `--batch-size` + `--network` paginated | `class-wppo-cli-command.php:86,102,180,330,864` + `class-database-cleanup.php:138` | P1 |

---

## 4. Sources (every claim `Source URL` prioritised `make.wordpress.org/cli` primary)

| Handbook guidance | URL |
|---|---|
| Synopsis `<pos>` vs `[<pos>]` vs `--assoc` vs `--flag`; `--- default + options:` | `make.wordpress.org/cli/handbook/guides/commands-cookbook/#longdesc` + `handbook/references/argument-syntax` via `WP-CLI-RESEARCH.md §2-§3` |
| `WP_CLI::confirm($q,$assoc_args)` `--yes` + `--confirm` alias Rocket | `handbook/references/internal-api/wp-cli-confirm` + `github.com/wp-media/wp-rocket-cli: clean()` via `WP-CLI-RESEARCH.md §8` |
| `--dry-run` `search-replace --dry-run` | `developer.wordpress.org/cli/commands/search-replace/` via `WP-CLI-RESEARCH.md §8` |
| `WP_CLI\Utils\make_progress_bar` auto-`NoOp` when piped + DeliciousBrains `--batch-size default 500 max(1,(int)…)` recipe | `handbook/references/internal-api/wp-cli-utils-make-progress-bar` + `deliciousbrains.com/building-custom-wp-cli-commands-for-massive-data-migrations/` via `WP-CLI-RESEARCH.md §7,§10` |
| `WP_CLI\Utils\format_items` / `Formatter` `table\|json\|csv\|yaml\|count\|ids` + `--field/--fields` | `handbook/references/internal-api/wp-cli-utils-format-items` + `Formatter.php` + `developer.wordpress.org/cli/commands/user/list --fields` via `WP-CLI-RESEARCH.md §6` |
| `WP_CLI::add_command('wppo',Class)` class-as-collection + `@when after_wp_load` ignored when plugin-loaded + `@subcommand` for `object-cache/system-info` | `handbook/guides/commands-cookbook/#required-registration-arguments` + `documentation-standards` + `handbook/references/internal-api/wp-cli-add-command` via `WP-CLI-RESEARCH.md §1-§2` |
| `--url` global + `--network` `wp user list --network` + `cache flush` all-sites warning | `handbook/references/config --url` + `developer.wordpress.org/cli/commands/cache/flush` via `WP-CLI-RESEARCH.md §11` |
| LSCWP `litespeed-purge all/url/blog` + `litespeed-option all --format` 6 formats + `rocket clean --blog_id` + Yoast `index` bar | `docs.litespeedtech.com/lscache/lscwp/cli/` + `docs.wp-rocket.me/article/1497` + `github.com/wp-media/wp-rocket-cli: clean prog` via `ECOSYSTEM-RESEARCH.md §4.1.8/§4.1.4/§4.1.6` |

*Research-only, no production edits. Feeds `WP-CLI-GAPS.md` (priority table), `HOOK-PROPOSED-DESIGN.md` (Before/During/After specs), `WP-CLI-PROPOSED-DESIGN.md` (per-subcommand `Arguments`/`Options`/`Permissions`/`Output`/`Errors`/`Examples`), `BRAINSTORM.md` (A/B/C) with consistent P0-3.*
