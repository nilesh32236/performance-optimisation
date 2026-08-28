# ECOSYSTEM-RESEARCH — wp-cli-hooks (Aggregate)

**Date:** 2026-08-28
**Workspace:** `docs/research/wp-cli-hooks/`
**Method:** Official handbook (`make.wordpress.org/cli`, `developer.wordpress.org/cli`) primary; command cookbook, argument syntax, documentation standards, internal API; mature plugins (WooCommerce `wp wc`, WP Rocket `wp rocket`, LiteSpeed Cache `wp litespeed-purge`, W3 Total Cache, WP Super Cache, `wp cache`). Every claim carries Source URL → Relevant guidance → Why applies. Read-only.

> Detail per section: `WP-CLI-RESEARCH.md` (WP-CLI conventions), `HOOK-AUDIT.md` (hooks), `WP-CLI-CURRENT.md` (current CLI state).

---

## 1. WP-CLI — Official Conventions vs WPPO (`wp wppo`)

*Full source-URL × guidance × why-applies matrix with verbatim handbook quotes and mature-plugin contrasts is in [`WP-CLI-RESEARCH.md`](./WP-CLI-RESEARCH.md) (16 sections, §1-§16). This section is the merge-ready executive summary for the repo-wide `docs/research/ECOSYSTEM-RESEARCH.md` aggregator.*

### 1.1 Snapshot — `wp wppo` as WP-CLI sees it

`PerformanceOptimise\Inc\WPPO_CLI_Command` (`includes/class-wppo-cli-command.php:1-973`, `@since 1.9.0`, `WP_CLI::add_command('wppo', …)` at `includes/class-main.php:472-474`) exposes 7 `@when after_wp_load` subcommands under `wp wppo` — `cache` (`:75`), `database` (`:174`), `image` (`:321`), `settings` (`:573`), `object-cache` (`:801` via `object_cache` method), `pagespeed` (`:902`), `system-info` (`:956`). Full file:line-cited current-state is [`WP-CLI-CURRENT.md`](./WP-CLI-CURRENT.md); aggregator stub [`CURRENT-STATE.md`](./CURRENT-STATE.md).

### 1.2 How WPPO tracks (or diverges from) official & ecosystem

| Dimension | Handbook & ecosystem norm | WPPO (`wp wppo`) | Verdict |
|-----------|---------------------------|------------------|---------|
| **Naming** `wp <ns> <verb>` | Handbook `wp plugin install`, `wp cache flush`, `wp post list` (noun+verb, kebab via `@subcommand` when PHP reserved). Woo `wp wc <resource> <action>`, Rocket `wp rocket clean/preload`, LSCWP `wp litespeed-purge all/url/blog` — flat `<root> <action>` tree | `wp wppo cache {clear|preload|status}` etc. (`wppo` short namespace + `@subcommand` for `object-cache`/`system-info`) — mirrors core `cache` + Rocket/LSCWP patterns | **Aligned** |
| **Help** Docblock → synopsis | Handbook cookbook: longdesc `## OPTIONS` defines synopsis (`<req>` vs `[<opt>]` vs `[--assoc=<v>]` vs `[--flag]`, `--- default: + options:` YAML); `@subcommand` for kebab/reserved; `@when after_wp_load` (plugin-loaded → ignored, WP already loaded); class-level shortdesc + `## EXAMPLES` (`# comment` / `$ wp …` / `Success:`) | All 7 have `## OPTIONS`+`## EXAMPLES`+`@when after_wp_load`+`@subcommand` where needed — near Doc Standards compliant; gaps: `<action>` marked required when code defaults (`?? 'clear'` etc.) should be `[<action>]`; no `Sample Output` lines | **Minor fix** |
| **Args** positional vs assoc | Handbook `argument-syntax`: `<hook>` required vs `[<hook>]` optional vs `<file>...` variadic; assoc `--dbname=<v>` required vs `[--dbhost=<v>]` optional vs `[--flag]` boolean vs `[--field[=<v>]]` optional-value; placeholders `<id>`/`<file>`/`<url>`/`<format>`; `format_options` `[--format=<format>]` | Uses positional `<action>`/`[<group>]`/`[<tab>]` + assoc `--page/--type/--tables/--format/--file/--settings/--host…` correctly; comma-list `--tables=posts,postmeta,…` mirrors Rocket `--post_id=2,4` + `explode(',')` at `:179`; no boolean `[--flag]` (`--dry-run/--yes`), no variadic | **Aligned; missing flags** |
| **Validation** | Synopsis `options:` enum validates before invoke (`Invalid value specified for '--type'`); else manual `WP_CLI::error` inside method (`Error: Invalid …` exit 1). ` --dry-run` for mutations, confirm for destructive. | 3 enums via `---` satisfy pre-invoke; rest manual allowlists at runtime (`cache` `:93-96`, `database` `:208,275`, `image` `:390`, `object-cache` `:851-854`) + `warning` for skip (`database` `:184,220`) — works but later error. No `--dry-run`, no `confirm`/`--yes` on destructive (`cache clear` all, `database cleanup --type=all`, `object-cache disable`, `settings import`) | **Gap — add synopsis enums + dry-run/yes** |
| **Exit codes** | Handbook `exit-codes`: 0 success (primary condition satisfied), 1 failed (operational error) or binary “condition not met” (`is-installed` 0/1 via `error/halt`). `success` → `Success:` stdout 0, `error` → `Error:` stderr 1 (debug backtrace with `--debug`), `warning` → `Warning:` stderr continue 0, `halt($n)` specific. Bundled warn-for-skip precedes success (Issue #1195). | Uses all three correctly: `error` for invalid/missing/FS/DB (`:95,:208,:275,:606,:632,:848,:910,:962`), `warning` for skip/partial (`:184,220,731,923`), `success` for ok (`:81,120,196,365,615,744,817`…). One debate: `pagespeed results` absent → `warning` exit 0 vs binary-not-found `error` 1 (handbook `is-installed` uses 1) | **Aligned; one policy choice** |
| **Output** `--format` / `--fields` | `format_items($format,$items,$fields)` / `Formatter` → `table|json|csv|yaml|count|ids` + `--field` singular + `--fields` list; globals `--quiet` suppresses `log/success`, `--debug` verbose. Data commands “often accept” `--format`, `--fields`. | Only `settings get --format=json|yaml` (`:411-417` Spyc/yaml_emit) — others hardcode `wp_json_encode(PRETTY)` + `WP_CLI::log`. No `table/csv/count/ids`, no `--field/--fields`, no `format_items/Formatter` anywhere (grep 0). `cache status` human 3 `log` lines `:87-89` not JSON-parseable, others always JSON pretty | **Gap — add `Formatter` + `--format/fields`** |
| **Progress** `make_progress_bar` | Handbook `WP_CLI\Utils\make_progress_bar($msg,$count, $interval=100)` → `tick()/finish()`, auto-`NoOp` when piped, shows % + elapsed/total. DB-migration recipe: `--batch-size` + generator `yield` + per-batch `wp_cache_flush`. Rocket: `make_progress_bar('Delete cache files', count($blog_ids))` per `--blog_id/--lang/--permalink` batch + `tick` | Zero bars. Candidates: `image convert` 100 files × 0.2-1s decode, `database cleanup --type=all` 9-serial LIMIT 1000 loops, `cache status` 5000-file walk, `database optimize` table locks | **Gap** |
| **Dry-run / --yes / --confirm** | `search-replace --dry-run` to preview; `WP_CLI::confirm($q,$assoc_args)` respects `--yes` (bypasses prompt, `fwrite(STDOUT…)+fgets` else exit). Rocket `wp rocket clean` → `WP_CLI::confirm('Delete all cache files ?')` unless `--confirm` | None — destructive runs non-interactively (CI-safe but risky prod). No `--dry-run` preview of row/file counts; no `confirm`/`--yes` (`--confirm` alias Rocket) | **Gap** |
| **--quiet/--verbose/--prompt** | Global `--quiet` suppresses `log/success`; `--debug[=<group>]` verbose; `--prompt[=]` interactive. | Uses `log/success/warning/error` (quiet-aware) correctly; no `debug/line/verbose` branches; `settings --prompt` not needed (synopsis drives it) | **Aligned** |
| **Batching** | DB-migration handbook: `[--batch-size=<n>] default:500` (`max(1,(int)…)`), periodic `wp_cache_flush`. Bundled `wp user generate --count=500` + Rocket CSV chunking. | Service-layer batching exists (`DELETE LIMIT 1000` `:138-180`, image `batch 50` `:330`, sitemap 500 cap, `TRASH` 1000) but no CLI knob `[--batch-size]`/`[--limit]` | **Gap — expose flag** |
| **Multisite** `--url/--network/--blog_id` | Global `--url=<url>` “In multisite, how target site is specified.” Bundled `wp user list --network` sets `blog_id=0`; LSCWP `litespeed-purge network_list|all|blog <id>` via `switch_to_blog`+`get_blog_details`; Rocket `wp rocket clean --blog_id=2[,4]` via `switch_to_blog` loop + `MULTISITE` guard. Core warning: persistent `cache flush` all-sites | WPPO none — relies on WP-CLI `--url` to select blog (correct but undocumented); no `--network` sweep, no `--blog_id`, no `switch_to_blog`. Helpers multisite-safe (`Util::get_settings` per-blog, `Cache` domain dirs, `Util::transient_key` blog prefix) but CLI not explicit. `object-cache flush` all-sites warning missing | **Gap — document + add `--network`** |
| **Performance** | Bootstrap cost dominates; `@when` ignored for plugin-loaded commands (doc) → effectively `after_wp_load`. Purge-all multisite warns prod impact. | All `@when after_wp_load` (correct). `cache status` walk 5000 files / `search-replace --dry-run` analogy for DB — no bar | **Aligned** |
| **Bootstrap** | Handbook “Include in a plugin”: `if (defined('WP_CLI')&&WP_CLI) WP_CLI::add_command` after vendor autoload, before hooks. Dist as standalone needs `composer.json type wp-cli-package`. | `class-main.php:472-474` inside `Main::includes()` after `vendor/autoload.php` (`performance-optimisation.php:41` + `:437-439` action-scheduler) before `setup_hooks()` — verbatim | **Aligned** |

### 1.3 Primary sources (prioritised official)

| Pri | URL | What it governs |
|-----|-----|-----------------|
| Primary | https://make.wordpress.org/cli/handbook/guides/commands-cookbook/ | `@when`/`@subcommand`/longdesc→synopsis, registration `$args` vs docblock, help rendering, class-as-collection |
| Primary | https://make.wordpress.org/cli/handbook/references/argument-syntax/ | `<required>` vs `[<optional>]` vs `[--flag]` vs `--opt=<v>`, placeholders `<id>/<file>/<url>/<format>`, `format`/`fields` patterns |
| Primary | https://make.wordpress.org/cli/handbook/references/documentation-standards/ | shortdesc <50, `## OPTIONS`/`## EXAMPLES` shape, `--- default/options ---`, examples `Sample Output` |
| Primary | https://make.wordpress.org/cli/handbook/references/internal-api/wp-cli-add-command/ | third-arg synopsis array, `when`, `before/after_invoke` |
| Primary | https://make.wordpress.org/cli/handbook/references/exit-codes/ | 0 success, 1 failed / binary-not-met, `error/halt` vs `warning`, `$?` shell-conditional |
| Primary | https://make.wordpress.org/cli/handbook/references/internal-api/wp-cli-success/ + /wp-cli-error/ + /wp-cli-warning/ + /wp-cli-halt/ + /wp-cli-confirm/ + /wp-cli-log/ + /wp-cli-utils-make-progress-bar/ + /wp-cli-utils-format-items/ | output tiers `success/Error/Warning/halt`, `confirm … $assoc_args` skips with `--yes`, `log` vs `line` vs `debug`, `make_progress_bar` + piped `NoOp`, `format_items/Formatter` `table|json|csv|yaml|count|ids` |
| Primary | https://make.wordpress.org/cli/handbook/references/config/ + https://developer.wordpress.org/cli/commands/cache/flush/ + /search-replace/ + /user/list/ + /post/list | globals `--url/--network/--quiet/--debug/--prompt`, `search-replace --dry-run`, `cache flush` all-sites warning, `user list --network` multisite pattern |
| Secondary | https://developer.woocommerce.com/docs/wc-cli/cli-overview + https://developer.woocommerce.com/docs/wc-cli/wc-cli-commands/ | `wp wc <resource> <action>` REST-bridged `list/get/create/update/delete`, `tool run` `clear_transients`, `--format` `table|json|csv|yaml|count|ids`, `--user` |
| Secondary | https://docs.wp-rocket.me/article/1497-wp-cli-interface-for-wp-rocket + https://github.com/wp-media/wp-rocket-cli/blob/trunk/command.php | `wp rocket clean --post_id|--permalink|--lang|--blog_id [--confirm]` + `preload [--sitemap]`, progress per batch `make_progress_bar`, `confirm` + `--confirm` alias, `switch_to_blog` |
| Secondary | https://docs.litespeedtech.com/lscache/lscwp/cli/ + https://blog.litespeedtech.com/2018/03/14/using-lscache-with-the-wordpress-cli/ + https://github.com/litespeedtech/lscache_wp + https://github.com/igniteonline/litespeed-cache/blob/master/cli/class-litespeed-cache-cli-purge.php | `wp litespeed-purge network_list|all|blog|url|category|tag|post_id`, colorized network list, `http_request` purge + nonce, multisite `blog` validation |

*Fetched 2026-08-28; cross-checked handbook git `wp-cli/handbook` mirrors.*

### 1.4 Recommendations (not enacted — research)

**Must-fix handbook correctness:**

1. Synopses `<action>` → `[<action>]` where code defaults (`cache:49`, `database:131`, `image:301`, `object-cache:757`, `pagespeed:880`).
2. Enumerate `options:` YAML for all action verbs (pre-invoke validation before manual `WP_CLI::error`).
3. Add `--format table|json|csv|yaml|count` + `Formatter`/`format_items` + `[--fields]/[--field]` to `cache status`, `database counts`, `image status`, `object-cache status`, `system-info`, `pagespeed results` (current only `settings get` `json|yaml`).
4. Destructive `WP_CLI::confirm` + `[--yes]` (`--confirm` alias Rocket) to `cache clear` (all), `database cleanup --type=all`, `object-cache disable`, `settings import`.
5. `[--dry-run]` preview for `database cleanup`, `cache clear`, `image convert` (`Dry run…` vs `Success`).

**Should — ecosystem parity:**

6. `make_progress_bar` for `image convert` 100, `database cleanup --type=all` 9-serial, `cache status` 5000 walk, `database optimize` table locks, `cache preload` 500 sitemap (disabled when piped).
7. Multisite: help note “In multisite use `wp --url=<site> wppo …`; add `[--network]` sweep (`get_sites()` + `switch_to_blog`/`restore_current_blog`, Rocket/LSCWP pattern) + `[--blog_id]` CSV compat; warn `object-cache flush` all-sites (persistent `cache flush` note).
8. `[--batch-size=<n>]`/`[--limit=<n>]` knob (DB-migration `500` default, `max(1,…)`, per-batch `wp_cache_flush`) for `image convert` / `database cleanup`.
9. Complete `get_default_settings():451-522` with 7 tabs `litespeed_integration,llms_txt,od_integration,bfcache,perf_translations,ai_adaptive,edge_cache` (vs `class-main.php:240-265`) so `settings get` not false `Unrecognized tab` `731`.
10. `object-cache` full allowlist `mode,nodes,master_name,use_tls,persistent,compression` vs current 6 (`:864-871` vs `class-rest.php:1104-1117`).
11. Close UI gaps `rum`, `activities`, `autoloaded_options`, `used_css/ccss`, `cron health`, `suggestions/web_vitals_trends` (matrix `WP-CLI-CURRENT.md §5`) or document absence in `wp help wppo`.

*See `WP-CLI-RESEARCH.md §15` ranking + §3-§13 guidance-for-each matrices (Source URL → Relevant guidance → Why applies to this plugin) with verbatim handbook excerpts.*

---

## 2. Hook-Audit — Summary (full in HOOK-AUDIT.md)

> Included for repo-wide aggregation. Full 272-hit audit with Hook, Type, File:Line, Purpose, Fires when, Args, Priority, Consumers, Public/Private, Docs — see [`HOOK-AUDIT.md`](./HOOK-AUDIT.md).

- Totals: ~128 `add_action`, ~38 `add_filter`, ~78 `apply_filters`, ~22 `do_action`, 2 lifecycle hooks — 272 prod hits (excl. `vendor/tests`).
- Categories audited: optimization decisions, cache gen/invalidation, asset CSS/JS, image lazy, HTML minify, DB cleanup, RUM, object cache, settings, REST, CLI, cron, admin, frontend, LiteSpeed/Core-Tweaks/LLMs/OD/Edge/AI/Perf-Translations/Abilities.
- CLI site: not a WP hook but `WP_CLI::add_command('wppo', Class)` at `class-main.php:472-474` (`after_wp_load`, 7 subcommands); no `add_action/add_filter` in CLI file — pure `WP_CLI::{success,error,warning,log}`.
- Drift: `docs/hooks.md` documents 42 public `wppo_*` but omits ~15 `apply_filters` firing sites (`wppo_filesize_limit_bytes`, `wppo_cron_discovery_limit`, `wppo_server_timing_enabled`, etc.) — documented in §15 of HOOK-AUDIT.

---

## 3. WP Core Hook Lifecycle Precision Audit — Is the Plugin on the Right Lifecycle?

> Full per-hook file:line table (33 verdicts, lifecycle diagram, version gates) → [`HOOK-CORE-RESEARCH.md`](./HOOK-CORE-RESEARCH.md). Research-only; no production edits. Each row cites `file:line` and compares current hook to WP core docs (`template_redirect`, `wp`, `wp_enqueue_scripts`, `shutdown`, `send_headers`, `admin_init`/`admin_menu`/`current_screen`, `rest_api_init`, `init`/`wp_loaded`/`shutdown`, `pre_get_posts`, `script_loader_tag`/`style_loader_tag`, `advanced-cache` drop-in, `pre_http_request`, `wp_generate_attachment_metadata`/`image_editor`).

### 3.1 Roll-up verdict

| # | Category | Current hook(s) | Verdict | File:Line | Action |
|---|----------|-----------------|---------|-----------|--------|
| 1 | Frontend cache buffer | `wp_template_enhancement_output_buffer:10` + `wp_finalized_template_enhancement_output_buffer` (6.9+) else `template_redirect` (legacy) | **Correct — dual is intentional, TODO #553** | `class-main.php:545-546,550` | Keep until min WP→6.9; no move to `send_headers`/`wp` (query/404 not ready) |
| 2 | Server-Timing | `template_redirect:0` capture + `wp_finalized…:0` emit | **Mostly correct — docs nit** | `class-main.php:560-561` | Header forces buffer; document streaming trade-off vs `send_headers` |
| 3 | Used-CSS / LCP buffers | `…_output_buffer:20/30` + `template_redirect:20` | **Correct** | `class-main.php:568,572,584,590` | Pipeline 10→20→30 order intentional |
| 4 | `send_headers` Link header | `send_headers → emit_link_header` | **Correct** | `class-main.php:634` | Keep — only header-legal hook |
| 5-7 | Admin lifecycle | `admin_menu:10`, `admin_init:10×4`, `admin_bar_menu:100`, `admin_enqueue_scripts:10` | **Correct** | `class-main.php:486-494,533` | Keep; `current_screen` micro-opt not worth coupling |
| 8 | `init:10` role-hash cookie / `wp_logout` | `init:10 set_role_hash_cookie` + `wp_logout clear` | **Correct** | `class-main.php:495-496` | Must be `init` (before cache drop-in decision); not `template_redirect` |
| 9 | `rest_api_init:10` 25 routes | `rest_api_init` | **Correct** | `class-main.php:615` / `class-rest.php:58` | Only correct REST hook |
| 10 | Cron | `init:10 schedule_cron_jobs` + `cron_schedules:10` | **Correct** | `class-cron.php:57,61` | `init` canonical per handbook; not `wp_loaded`/`shutdown` |
| 11 | `init:10` rewrite | `init → Llms::register_rewrite` + `query_vars` | **Correct** | `class-main.php:631-632` | Must be `init` |
| 12 | `wp_enqueue_scripts` chain (5→MAX) | `wp_enqueue_scripts:5/10/999/1000/10000/MAX-1/MAX` + `wp_head:0/1` | **Correct** | `class-main.php:497,498,536,608,611,619,…` | Fragile `PHP_INT_MAX` but fixes preload-vs-print race; `wp_print_styles` alternative noted but not needed |
| 13 | `script/style_loader_tag` + strategy | `script_loader_tag:10/11` `style_loader_tag:9/10` gated `is_wp63_plus`/`is_wp69_plus` | **Correct** | `class-main.php:515,523,525,530,662,679,688` | Dual strategy/lagacy correct per WP 6.3/6.9 gates |
| 14 | `wp:10` per-page delay | `wp → apply_per_page_delay_config` | **Correct** | `class-main.php:516` | Earliest ID-known hook; not `pre_get_posts` |
| 15 | `wp_footer:9999` capture + `wp_enqueue_scripts:9999` dequeue | `Asset_Manager` | **Correct** | `class-asset-manager.php:79,82` | `wp_footer:9999` sees `done` post-print; `9999` wins |
| 16 | `wp_generate_attachment_metadata:10` / `wp_get_attachment_image_src` | `Image_Optimisation` | **Correct** | `class-image-optimisation.php:185,187` | Canonical; `wp_after_insert_attachment` optional secondary |
| 17 | `pre_get_posts` | Not used | **Correctly unused** | — | No query mutation |
| 18 | `pre_http_request` | Not used | **Correctly unused** | `class-telemetry.php:227` | Per-URL `wppo_telemetry_verify_ssl` sufficient; global intercept would couple all HTTP |
| 19 | `shutdown` | `shutdown → commit_img_info` + `RUM::flush` | **Correct** | `class-img-converter.php:1750` / `class-rum.php:352` | Bulk pattern reduces N `update_option` to 1; `wp_footer` misses REST/cron |
| 20 | `save_post:10` / `deleted_post` | Inval + used-CSS queue + DB counts | **Correct** | `class-main.php:552,784,596-597` | Not `wp_after_insert_post` nor `transition_post_status` (misses meta-only) |
| 21 | `update_option_wppo_settings:10` + `add/delete_option` + `switch_blog:10` | Settings memo | **Correct** | `class-util.php:245-248` | Dynamic option hook precise; not generic `updated_option` |
| 22 | `wp_resource_hints:10(2)` | Preconnect/dns-prefetch | **Correct** | `class-main.php:760` | Keep |
| 23 | `advanced-cache.php` drop-in | Zero-hook (pre-`plugins_loaded`) | **Correct by design** | `class-advanced-cache-handler.php:128` | Only mechanism to bypass WP boot |

**Overall:** Plugin is on the right lifecycle for all 9 requested categories. The 6.9+ `wp_template_enhancement_output_buffer` migration (with `TODO #553` legacy) and version-gated `strategy`/`fetchpriority` (`TODO #553`, Trac #61734) are already precise. Recommend only doc/`args` hygiene: document `Server-Timing` streaming trade-off (`class-main.php:556-558`), expose REST `args` schemas, add missing `docs/hooks.md` entries (`wppo_filesize_limit_bytes`, `wppo_cron_discovery_limit`, `wppo_server_timing_enabled`, etc. flagged `HOOK-AUDIT.md §15`), and optionally note `current_screen` micro-opt — no hook moves.

*Source: WP `class-wp.php::main() → send_headers → query_posts → template_redirect`, `default-filters.php` priority table, `template.php::wp_should_output_buffer_template_for_enhancement()` (6.9), `rest-api.php:rest_api_init`, `cron.php:cron_schedules`. No production edits — see `HOOK-CORE-RESEARCH.md §§1-10` for per-hook File:Line + better-alternative table.*

---

*Append target for `docs/research/ECOSYSTEM-RESEARCH.md` repo aggregator. Keep `WP-CLI-RESEARCH.md` as the full source-URL matrix; this file is the condensed merge slice.*

---

## 4. Competitive / Ecosystem Patterns — CLI & Extension APIs (Adaptable Principles)

> **Scope:** 8 mature plugins + WP-CLI packages vs `wp wppo` (7 subcommands, `includes/class-wppo-cli-command.php:1-973`, `@since 1.9.0`). Research-only, no production edits. Extracts *principles* (not proprietary code) with Source URL → Relevant guidance → Why applies to this plugin. For handbook-level §1-§16 see [`WP-CLI-RESEARCH.md`](./WP-CLI-RESEARCH.md); for current CLI evidence see [`WP-CLI-CURRENT.md`](./WP-CLI-CURRENT.md).

### 4.0 Methodology & Sources Fetched 2026-08-28

Prioritised primary: `make.wordpress.org/cli` + `developer.wordpress.org/cli/commands/*`, plus live docs fetched via WebFetch/WebSearch for WooCommerce `wp wc`, WP Rocket `wp rocket`, LiteSpeed Cache `wp litespeed-*`, W3 Total Cache `wp w3-total-cache`, WP Super Cache `wp super-cache`, WP-CLI `search-replace`/`cache` packages, Yoast `wp yoast index`.

| # | Ecosystem | CLI surface examined | Fetched URL (representative) | Why relevant |
|---|-----------|----------------------|------------------------------|--------------|
| 1 | WooCommerce | `wp wc <resource> <verb>` REST-bridged (product, order, customer, coupon, tool) | https://developer.woocommerce.com/docs/wc-cli/cli-overview + /wc-cli-commands + wiki/WC-CLI-Overview (all fetched) | Gold standard for REST↔CLI parity, `--user`, `--format table/json/csv/yaml/count`, resource-verb grammar |
| 2 | WP Rocket | `wp rocket` via `wp-media/wp-rocket-cli:trunk` (clean/preload/regenerate/cdn/export/import/activate-cache) | https://docs.wp-rocket.me/article/1497-wp-cli-interface-for-wp-rocket + https://github.com/wp-media/wp-rocket-cli/blob/trunk/command.php:1-616 (fetched, 73★) | Closest cache-plugin analogue; package install pattern, `--confirm` alias for `--yes`, `make_progress_bar` per blog/lang, sitemap preload |
| 3 | LiteSpeed Cache | 8 families: `litespeed-option/purge/presets/image/online/debug/crawler/database` | https://docs.litespeedtech.com/lscache/lscwp/cli/ (fetched, full TOC) + blog/2018/03/14/… + github/lscache_wp purge class | Most feature-rich CLI; option get/set/all, purge granularity (network_list/all/url/blog/category/tag/post_id), multisite `blog <id>`, image push/pull, crawler, online nodes, database split |
| 4 | W3 Total Cache | `wp w3-total-cache flush <scope>` + `cdn_purge` | https://github.com/BoldGrid/w3-total-cache `Cli.php` + host knowledgebases (w3-total-cache `flush all`, `cdn_purge`) | Legacy scope-enum flush pattern |
| 5 | WP Super Cache | `wp super-cache enable/disable/flush/preload/status` | https://github.com/wp-cli/wp-super-cache-cli (58★) README + src | Minimal 5-verb cache CLI; same delegation questions as `wp wppo cache` |
| 6 | WP-CLI core | `wp cache flush`, `wp search-replace --dry-run --precise --recurse-objects`, `wp transient`, `wp option` | https://developer.wordpress.org/cli/commands/cache/flush/ + /search-replace/ + make.wordpress.org/cli handbook (fetched) | Canonical `--dry-run`, `--format`, `--fields`, multisite `--url/--network`, exit codes |
| 7 | Yoast SEO | `wp yoast index` (indexables 14.0) | https://yoast.com/developer-blog/yoast-seo-wp-cli-index-command + developer.yoast.com (fetched) | Large-dataset CLI with progress bars (posts/terms/archives), `--reindex`, lazy-load fallback |
| 8 | Object Cache Pro / Autocomplete | `wp cache` family + Redis | https://objectcache.pro + wp-cli/cache-command README (fetched) | Redis CLI overlap with `wp wppo object-cache` |

Proprietary internals not copied; only documented CLI synopsis, help shape, config & hook architecture.

---

### 4.1 CLI Interface Patterns Worth Adapting

#### 4.1.1 Naming & Topology — `wp <ns> <noun> <verb>` vs flat families

| Source URL | Relevant guidance | Why applies to this plugin |
|------------|-------------------|----------------------------|
| https://make.wordpress.org/cli/handbook/guides/commands-cookbook/#required-registration-arguments | `$name` like `plugin install` or `post list` via `WP_CLI::add_command($name, $callable)` | WPPO's `WP_CLI::add_command('wppo', Class)` at `class-main.php:472-474` + 7 `@subcommand` methods at `class-wppo-cli-command.php:69,168,315,567,795,896,954` follows cookbook class-as-collection (also in `WP-CLI-RESEARCH.md §1`). Keep single `wppo` root, not `litespeed-*`-style multi-root. |
| https://developer.woocommerce.com/docs/wc-cli/cli-overview | `wp wc <resource> <command>` (`product list/create/update/delete`, `tool run`) REST-backed, needs `--user=1` for auth | Validates depth-2 `wppo <subcommand> <action>` (`cache clear`, `database cleanup`) — analogous to `wp wc tool run clear_transients`. Depth-2 with positional `<action>` is handbook-correct; do not split into `wp wppo-cache-clear` flat verbs. Woo's `--user` hints multisite/auth pattern (see 4.1.8). |
| https://docs.litespeedtech.com/lscache/lscwp/cli/ — Table `litespeed-option/purge/presets/image/online/debug/crawler/database` (8 families) | `wp <command> <subcommand> [parameters]`; each family owns one concern (purge vs option vs image vs crawler) | WPPO's 7 tabs map 1:1 to LiteSpeed's family split (Cache≈purge, Settings≈option, Image≈image, Database≈database). LiteSpeed's granularity shows where WPPO will grow: add `wp wppo crawler` or `wp wppo used-css` only when feature needs it; do not pre-split. |
| https://github.com/wp-media/wp-rocket-cli/blob/trunk/command.php + docs.wp-rocket.me/article/1497 | Single `rocket` root with verbs `clean/preload/regenerate/cdn/export/import` | Confirms flat verb under one root works for 40k-install plugin; `clean --post_id/--permalink/--lang/--blog_id` vs WPPO `cache clear --page=<url>` — both valid paginated purges; prefer explicit `cache clear` over Rocket's generic `clean`. |
| https://github.com/BoldGrid/w3-total-cache + https://github.com/wp-cli/wp-super-cache-cli | `wp w3-total-cache flush <scope>` (all/pgcache/minify/dbcache/object) / `wp super-cache flush [--post_id|--permalink] / preload / status / enable/disable` | Shows scope-enum alternative to WPPO's action switch. WPPO's `cache clear|preload|status` enum inside one `@subcommand cache` already covers scopes; avoid adding `wp wppo flush pgcache` duplicates. |
| https://developer.wordpress.org/cli/commands/cache/flush/ | Core `wp cache flush` singular verb | WPPO `cache clear` aligns with core `cache` family; consider alias `wp wppo cache flush` → `clear` for `cache flush` muscle memory (handbook `@subcommand` alias not needed — just document). |

**Adapted principle — Topology:** Keep `wp wppo` single namespace, depth-2 `wppo <tab> <action>`, kebab via `@subcommand` for `object-cache/system-info` (`class-wppo-cli-command.php:796,952`). Do not emulate LiteSpeed's multi-root `litespeed-*` until feature count forces it (≥10 families).

#### 4.1.2 Help Rendering — Synopsis, `@when`, `@subcommand`, Examples

| Source URL | Relevant guidance | Why applies |
|------------|-------------------|-------------|
| https://make.wordpress.org/cli/handbook/guides/commands-cookbook/#longdesc + references/documentation-standards | `## OPTIONS` → synopsis (`<req>` vs `[<opt>]` vs `[--assoc=<v>]` vs `[--flag]`; `--- default/options ---` YAML; `## EXAMPLES` with `# comment` + `$ wp …` + `Success:`; `@subcommand` for kebab/reserved; `@when after_wp_load` | WPPO's 7 docblocks already near-compliant (see `WP-CLI-RESEARCH.md §2`). Same shape across Woo (`wp wc product create --name=… --regular_price=…` examples), Rocket (`wp rocket clean --post_id=2,4,6,8` with 8 examples), LiteSpeed (per-subcommand Syntax + Example blocks). Keep. One fix: `<action>` → `[<action>]` where code defaults (`cache:76 ?? 'clear'`, `database:175 ?? 'cleanup'` etc.) — see `ECOSYSTEM-RESEARCH.md §1.2 gap`. |
| https://github.com/wp-media/wp-rocket-cli/blob/trunk/command.php: `clean` docblock | `[--post_id] [--permalink] [--lang] [--blog_id] [--confirm]` each as optional `[--flag]` + variadic CSV via `explode(',')` | WPPO `database optimize --tables=posts,…` at `class-wppo-cli-command.php:179 explode(',',$tables)` mirrors Rocket CSV; handbook-correct via `[--tables=<csv>]` assoc. |
| https://docs.litespeedtech.com/lscache/lscwp/cli/#litespeed-option-all | `wp litespeed-option all [--format=<format>] table|json|csv|yaml|ids|count` | Shows `--format` belongs even on `all` enumerations — WPPO `cache status`/`database counts` should expose same (see 4.1.4). |

**Adapted principle — Help:** All new subcommands must ship `## OPTIONS` (with `--- default/options ---` for enums) + `## EXAMPLES` (≥2, with `Success:`/`Sample Output`) + correct `[<pos>]` brackets. Use `@subcommand` for any kebab method.

#### 4.1.3 Arguments — Positional vs `--assoc` vs `--flag` vs variadic

| Source URL | Relevant guidance | Why applies |
|------------|-------------------|-------------|
| https://make.wordpress.org/cli/handbook/references/argument-syntax/ | `<required>` vs `[<optional>]` vs `[<opt>...]`; `[--type=<v>]` vs `[--flag]` (boolean) vs `[--field[=<v>]]` optional-value; `format`/`fields` pattern | WPPO uses positional `<action>` + assoc `--type/--tables/--format/--file/--settings/--host…` correctly (see `WP-CLI-CURRENT.md §2.3`). Missing only boolean flags — add `[--dry-run]`, `[--yes]`/`[--confirm]` alias, `[--verbose]`. |
| https://github.com/wp-media/wp-rocket-cli — `wp rocket clean --confirm` / `regenerate --file=htaccess|advanced-cache|config --nginx=true` | Boolean flag `--confirm` skips `WP_CLI::confirm('Delete all cache files ?')`; `--nginx=true` boolean-value gate | WPPO destructive ops (`cache clear` all, `database cleanup --type=all`, `object-cache disable`, `settings import`) should mirror Rocket's `confirm` + handbook `--yes`. Check both `Utils\get_flag_value($assoc,'yes') || Utils\get_flag_value($assoc,'confirm')` for compat. |
| https://docs.litespeedtech.com/lscache/lscwp/cli/#litespeed-purge-category — `wp litespeed-purge category <ids>` variadic `<ids>...` space-separated | `<ids>` variadic (`category 1 3 5`) vs WPPO `--tables=<csv>` comma | Both valid; CSV (`--tables=posts,postmeta`) is better for scripting; keep CSV and use `explode`, as `WP-CLI-RESEARCH.md §3` notes. |
| https://developer.wordpress.org/cli/commands/search-replace/ + wp-cli/search-replace-command README | `[--dry-run]` (no DB writes), `--skip-columns=guid`, `--skip-tables=wp_users`, `--precise`, `--regex`, `--include-columns` | Canonical `--dry-run` for mutations — WPPO `database cleanup` + `cache clear` + `image convert` need same preview mode (see 4.1.5). |
| https://developer.woocommerce.com/docs/wc-cli/cli-overview + docs/wc-cli/using-wc-cli.md | `wp wc product create --name="…" --type=simple --regular_price="19.99"` splat `--<field>=<value>` arbitrary keys + required `--user=<id>` | Woo's splat shows JSON alternative: WPPO `settings update … --settings='{"minifyHTML":true}'` is JSON string (correct for nested tabs); could also offer `--<key>=<val>` splat later but JSON preserves nesting — keep JSON, document quoting. |

**Adapted principle — Args:** Value-assocs for required payloads (`--page=<url>`, `--file=<path>`, `--settings=<json>`), comma-CSV for lists (`--tables`, `--blog_id` future), boolean flags for dry-run/yes/verbose, `--- options:` enums to get pre-invoke validation before manual `WP_CLI::error` at `class-wppo-cli-command.php:93-96,206-209`.

#### 4.1.4 Output Modes — `--format`, `--fields`, `Formatter`, `format_items`, `WP_CLI::log` vs `line`

| Source URL | Relevant guidance | Why applies |
|------------|-------------------|-------------|
| https://make.wordpress.org/cli/handbook/references/internal-api/wp-cli-utils-format-items/ + Formatter.php | `format_items($format, $items, $fields)` / `Formatter::display_items()` → `table|json|csv|yaml|count|ids` + `--field`/`--fields` | WPPO only `settings get --format=json|yaml` at `class-wppo-cli-command.php:690-703` uses manual `wp_json_encode/Spyc/yaml_emit`; other data commands (`database counts:202`, `image status:385`, `object-cache status:808`, `system-info:965`) hardcode `wp_json_encode(PRETTY)` + `log` — should use `Formatter` so `wp wppo database counts --format=table` is human-readable and `| jq` remains scriptable. |
| https://docs.litespeedtech.com/lscache/lscwp/cli/#litespeed-option-all + #litespeed-online-sync | `wp litespeed-option all [--format=<format>]` with 6 format options; `wp litespeed-online sync/services/nodes [--format=<format>] table|json|csv|yaml|ids|count` | Proves even "admin" commands need full `--format` enum; `litespeed-option all --format=csv` example is template for `wp wppo settings get --format=csv` or `wp wppo system-info --format=table`. |
| https://github.com/woocommerce/woocommerce/blob/trunk/docs/wc-cli/wc-cli-commands.md + wiki/WC-CLI-Overview | `wp wc product list --format=json --per_page=…` + `wp wc tool list` via `format_items` table default | Woo's REST-backed list shows default `table` for humans pipes to `json` for scripts — same for WPPO `database counts`, `image status`, `system-info`. |
| https://github.com/wp-cli/cache-command README — `wp cache get/set/flush`, `wp transient type`, `wp cache supports <feature>` exit-code probes | Cache commands plus `wp cache flush` "Beware multisite all-sites" warning, `halt(1)` for binary not-found | WPPO `cache status` three `log(sprintf(...))` lines at `:87-89` human-only → should be `--format` switchable: `table` preserves current, `json` returns `{"size":"…","cached_pages":42,…}`. |

**Adapted principle — Output:** Add `WP_CLI\Formatter` (or `Utils\format_items`) + `[--format=<format>]` with `--- default: table + options: table,json,csv,yaml,count ---` and `[--fields=<fields>] [--field=<field>]` to every data command (gap `WP-CLI-RESEARCH.md §6`, `ECOSYSTEM-RESEARCH.md §1.2 #3`). Keep `log` for data, `success` for conclusion, `line` only if `--quiet` must not suppress data.

#### 4.1.5 Dry-Run, Confirm, Exit Codes

| Source URL | Relevant guidance | Why applies |
|------------|-------------------|-------------|
| https://developer.wordpress.org/cli/commands/search-replace/ | `[--dry-run] Run search/replace and show report, but don't save to DB` canon | Mutating WPPO ops should offer `[--dry-run]` preview counts (“would delete 1,243 revisions / 42 transient rows / 187 cache files”) before `Cache::clear_cache()` or `Database_Cleanup::clean_all()` at `class-wppo-cli-command.php:117,215`. |
| https://make.wordpress.org/cli/handbook/references/internal-api/wp-cli-confirm/ + WP_CLI::confirm source | `WP_CLI::confirm($q, $assoc_args)` bypassed by `--yes` (global `--no-color` etc. table) | Add `WP_CLI::confirm('Delete all static HTML cache? [y/n]', $assoc_args)` to `cache clear` (all), `database cleanup --type=all`, `object-cache disable`, `settings import` — current none at `grep confirm 0` (`WP-CLI-CURRENT.md §2.2`). Support both `--yes` (handbook) + `--confirm` (Rocket alias) via `get_flag_value`. |
| https://github.com/wp-media/wp-rocket-cli/blob/trunk/command.php — `clean()` clean branch | `if (!empty($assoc['confirm'])) WP_CLI::line('Deleting all cache files.') else WP_CLI::confirm('Delete all cache files ?')` + same for `--post_id/--blog_id` CSV batches | Proves cache-purge needs confirm gate; automation uses `--confirm`. WPPO can copy verbatim check. |
| https://make.wordpress.org/cli/handbook/references/exit-codes/ + internal-api/wp-cli-success/error/warning/halt | `success` → 0, `error` → 1, `warning` → continue 0, `halt(n)` for binary probes (`plugin is-installed` 0/1) | WPPO already uses `error`/`warning`/`success` correctly (`WP-CLI-CURRENT.md §2.2`); one policy choice: `pagespeed results` absent currently `warning` exit 0 at `:923` vs handbook binary `error` 1 — align to `warning` but document as “missing = warning not error”. |

**Adapted principle — Safety:** Every destructive `wp wppo` subcommand gets `[--dry-run]` (report-only) + `WP_CLI::confirm` gated on `[--yes]` (`--confirm` alias). Non-existent resource (`pagespeed results` miss) stays `warning` (exit 0) unless a future `wp wppo is-cached --url=` binary probe needs `halt(1)`.

#### 4.1.6 Progress — `make_progress_bar`

| Source URL | Relevant guidance | Why applies |
|------------|-------------------|-------------|
| https://make.wordpress.org/cli/handbook/references/internal-api/wp-cli-utils-make-progress-bar/ + deliciousbrains DB_Migration_Command recipe | `$bar = Utils\make_progress_bar($msg,$count); … $bar->tick(); … $bar->finish();` auto-`NoOp` when piped, visible %/elapsed | No WPPO command uses it (grep 0, `PERF-RESEARCH.md §3.1` flags 5000-file cache-status walk). Candidates: `image convert` 100 files at `class-wppo-cli-command.php:343-360` (~0.2–1 s each), `database cleanup --type=all` 9 serial `LIMIT 1000` loops at `class-database-cleanup.php:138-180`, `cache status` 5000-file `dirlist`, `database optimize` per-table `OPTIMIZE TABLE` locks. |
| https://yoast.com/developer-blog/yoast-seo-wp-cli-index-command — `wp yoast index` | `Indexing posts 100% [==============================] 0:00 / 0:00` per type (posts/terms/archives/general) with separate bars | Best large-dataset UX: distinct bar per collection (posts vs terms). WPPO `database cleanup --type=all` should mirror with per-type bar or single bar `9 types`; `image convert` bar per format `webp/avif`. |
| https://github.com/wp-media/wp-rocket-cli/blob/trunk/command.php — `clean()` per `--blog_id/--lang/--permalink` loop | `$notify = make_progress_bar('Delete cache files', count($blog_ids)); foreach($blog_ids) { switch_to_blog + rocket_clean_domain + tick } finish()` | Shows even small counts (1–8) warrant a bar — WPPO `database optimize --tables=posts,…` 5 tables and future `--blog_id` multisite loop should use same. |

**Adapted principle — Progress:** Wrap any loop >10 items with `Utils\make_progress_bar`, `tick` per batch (respects `DISABLE_WP_CLI_PROGRESS_BAR`), `finish`. Suppress when piped automatically.

#### 4.1.7 Batching — `--batch-size`, `--limit`, per-batch `wp_cache_flush`

| Source URL | Relevant guidance | Why applies |
|------------|-------------------|-------------|
| https://deliciousbrains.com/building-custom-wp-cli-commands-for-massive-data-migrations/ — `--batch-size=<n> default:500` + `max(1,(int)…)` + `wp_cache_flush()` per batch + generator `yield` | Batch chunk canon | WPPO services already batch (`DELETE LIMIT 1000` at `class-database-cleanup.php:138-180`, image `batch 50` at `class-wppo-cli-command.php:330`, sitemap `500` cap at `class-cron.php:364`) but no CLI knob. Expose `[--batch-size=<n>] default:500` (or `50` for images) so operator trades memory vs wall time. |
| https://developer.wordpress.org/cli/commands/search-replace/ — `[<table>...]` variadic + `--precise` + `--recurse-objects` | Table scoping + mode switch | Could be `wp wppo database cleanup --type=revisions --batch-size=2000 --precise` analogue. |

**Adapted principle — Batching:** Add `[--batch-size=<n>]` / `[--limit=<n>]` to `image convert`, `database cleanup`, `database optimize`, `cache preload` — default to service defaults, sanitize `max(1,(int)…)`, flush runtime cache per batch.

#### 4.1.8 Multisite — `--url`, `--network`, `--blog_id`, `switch_to_blog`

| Source URL | Relevant guidance | Why applies |
|------------|-------------------|-------------|
| https://make.wordpress.org/cli/handbook/references/config/ + developer.wordpress.org/cli/commands/cache/flush — global `--url=<url>` “In multisite, how target site is specified”; persistent `cache flush` warns all-sites | Global `--url` selects blog; handbook says `cache flush` all-sites on multisite | WPPO relies on `--url` implicitly (doc `class-wppo-cli-command.php:33` notes “current blog selected by WP-CLI `--url`”) but never documents it. Document “In multisite use `wp --url=<site> wppo …`”. |
| https://docs.litespeedtech.com/lscache/lscwp/cli/#litespeed-purge-blog — `wp litespeed-purge blog <blogid>` + `network_list` table via `WP_CLI::line` + `colorize` + `get_blog_details` multisite branch | LiteSpeed validates `blog` requires `is_multisite`, else error; `purge all` tags all blogs; `litespeed-database <cmd> [blog <id>]` per-site DB op | WPPO needs explicit `[--network]` sweep (`get_sites()` + `switch_to_blog`/`restore_current_blog`, Rocket pattern at `command.php` `clean --blog_id` loop) + compat `[--blog_id]` CSV alias. Warn `object-cache flush` all-sites like `wp cache flush`. |
| https://github.com/wp-media/wp-rocket-cli/blob/trunk/command.php — `clean --blog_id=2,4,6` → `if (!MULTISITE) error` → `foreach explode(',',$blog_ids) { switch_to_blog + rocket_clean_domain + tick }` + `MULTISITE` guard | Full multisite loop with progress + guard | Template for `wp wppo cache clear --network` or `wp wppo database cleanup --network`. |
| https://developer.woocommerce.com/docs/wc-cli/cli-overview — `wp wc … --user=<id>` required | Auth/global pattern | Hints global `--user` for `tool run clear_transients` — WPPO REST has `manage_options` gate (`class-rest.php:357-361`) but CLI trusts shell user; no `--user` needed, but document that CLI has no capability check by design (see `WP-CLI-CURRENT.md §2.1`). |

**Adapted principle — Multisite:** Help text notes `In multisite use wp --url=<site> wppo …; add [--network] to run on all sites (switch_to_blog loop) and [--blog_id=<ids>] compat (CSV, Rocket/LSCWP)`. Helpers already multisite-safe (`Util::get_settings:91-157` per-blog memo, `Util::transient_key:781` prefix, `Cache` domain dirs) but CLI must expose sweep.

#### 4.1.9 Bootstrap & Performance — `@when`, cost, `setup_hooks` waste

| Source URL | Relevant guidance | Why applies |
|------------|-------------------|-------------|
| https://make.wordpress.org/cli/handbook/guides/commands-cookbook/#when — `@when after_wp_load` (default), `before_wp_load` for non-WP commands, ignored when plugin-loaded (WP already loaded) | `@when` ignored for plugin-loaded commands — WPPO's `@when after_wp_load` at 7 methods is correct but redundant (see `WP-CLI-RESEARCH.md §14`, `PERF-RESEARCH.md §1.1`) | Keep `@when after_wp_load` for documentation; no need for `before_wp_load`. |
| https://make.wordpress.org/cli/handbook/guides/commands-cookbook/#include-in-a-plugin-or-theme — `if(defined('WP_CLI')&&WP_CLI) WP_CLI::add_command(...)` after `vendor/autoload.php` before hooks | Validates `class-main.php:472-474` placement inside `Main::includes()` after `vendor/autoload.php` at `performance-optimisation.php:41` | Keep. |
| https://developer.wordpress.org/cli/commands/cache/flush/ — `Beware performance impact when flushing object cache in production` | All-sites flush warning | WPPO `object-cache flush` + `cache clear` all-sites need same warning in help. |
| `PERF-RESEARCH.md §2-6` (measured) | `setup_hooks()` ~70–105 registrations, but CLI fires ~5–10; `Util::get_settings`/`cached_home_url` already memoized, but `Cron::schedule_cron_jobs` `wp_next_scheduled ×8` costs 0.3–1.0 ms on CLI | Gate `setup_hooks` frontend/Admin/Cron sections on `Context::is_frontend()/is_admin()/should_schedule_cron()` per `ARCH-RESEARCH.md §4-5` so `wp wppo system-info` (read-only) does not boot `Image_Optimisation:343` etc. — prerequisite to fast CLI. |

**Adapted principle — Bootstrap:** Bootstrap cost dominates CLI wall time; context fences (`ARCH-RESEARCH.md §5` `Context` helper) are higher ROI than more subcommands.

#### 4.1.10 Distribution — `wp package install` vs plugin-bundled `wp wppo`

| Source URL | Relevant guidance | Why applies |
|------------|-------------------|-------------|
| https://github.com/wp-media/wp-rocket-cli + packagist.org/packages/wp-media/wp-rocket-cli — `type: wp-cli-package`, `wp package install wp-media/wp-rocket-cli:trunk` external package (73★, `wp-cli/wp-cli: ^2` peer) + `command.php` standalone `WPRocket_CLI extends WP_CLI_Command` | External package keeps CLI out of plugin bundle; trade-off: user must `wp package install` | WPPO ships bundled (`if(WP_CLI) add_command('wppo', Class)` inside plugin `includes/`), so `wp wppo` works immediately after plugin install — better for shared hosts where user cannot run `wp package install`. External package only makes sense if CLI needed without plugin active (not the case here). Stay bundled; Rocket's pattern is alternative, not better for this plugin. |
| https://github.com/wp-cli/wp-super-cache-cli — same `wp-cli-package` split (`enable/disable/flush/preload/status`) | Minimal split — `wp super-cache flush` vs core `wp cache flush` overlap | WPPO bundles but uses hyphen subcommands `object-cache`/`system-info` mirroring core `cache` pattern — consistent. |

**Adapted principle — Distribution:** Keep bundled registration (`AGENTS.md:18` manual `Main::includes()` includes CLI file). No external `wp-cli-package` split needed; if WP.org review ever demands lighter main plugin, consider `wppo-cli` companion package later.

---

### 4.2 Config API Patterns — Settings Get/Set/All/Export/Import/Reset, `--format`, `--fields`

| Ecosystem | Config surface | Pattern | Adapt for WPPO |
|-----------|----------------|---------|---------------|
| LiteSpeed `litespeed-option set <key> <value>` / `get <key>` / `all [--format]` / `export [--filename=<path>]` / `import <file>` / `import_remote <URL>` / `reset` — docs.litespeedtech.com/lscache/lscwp/cli/#litespeed-option | Key-path set (`cache-priv`, `'cdn-mapping[url][0]'`, multi-line `$'a\nb'`), whole-option `all --format`, file export to `CURRENTDIR/lscache_wp_options_DATE-TIME.txt`, ascii file `key=val` per line for import (comment `;`), remote URL import, factory reset | **Borrow:** `wp wppo settings get [<tab>] [--format=<format>] [--fields=<csv>]` (add `--fields`/`--field` filtering, full `--format` enum `table|json|csv|yaml|count|ids` per 4.1.4), `wp wppo settings export [--file=<path>]` (current) + maybe `[--filename=<path>]` alias for LiteSpeed compat, `import <file>` accepting both JSON (current at `class-wppo-cli-command.php:636-641`) and `litespeed-option import_remote <URL>` variant for remote migration. Keep sensitive-strip (`object_cache.password`, `performance_audit.pagespeed_api_key` at `:593-599`). |
| LiteSpeed `litespeed-presets apply <preset>` / `get_backups` / `restore <n>` | Preset apply + backup list + timestamp restore | **Borrow if presets introduced:** `wp wppo settings preset apply <name> [--dry-run]` + `wp wppo settings preset list --format=table` + `backups`/`restore`. LiteSpeed stores backup on apply — WPPO could `set_transient('wppo_settings_backup_'.time(), $before)` on `update_option`. |
| WP-CLI `wp option get/set/update/delete <key> [--format=json|yaml]` + `wp config set/get` | Single option key with value; `--format=json` passthrough, autoload flag, `--url` scoping | WPPO already wraps `wppo_settings` (single serialised array at `CLASS-UTIL:145-157` via `Util::get_settings`). `wp wppo settings get --format=json` could internally delegate to `wp option get wppo_settings --format=json` after sanitize, but keep tab filtering (`file_optimisation`, `preload_settings` etc.) as value-add over raw `wp option`. |
| Woo `wp wc tool list/run` + `wp wc settings` | `tool list --user=1 --format=table` then `tool run --user=1 clear_transients` | WPPO `database counts` / `system-info` are analogous tool-list probes; `tool run` embodies action+context (`--user`). WPPO destructive ops do not need `--user` (no capability check, `WP-CLI-CURRENT.md §2.1`) but document “any shell user with `wp` access can run”. |
| Yoast indexables `wp yoast index [--reindex]` | Index rebuild `wp yoast index` with per-collection bars (`posts 100% … 0:00`) + `wp yoast reindex` alias; test helper `Reset Indexables` before re-run | **Borrow for reindexable jobs:** `wp wppo image convert` and `database optimize` are reindex-like; add `--reindex`/`--force` to purge existing (e.g. `wp wppo image convert --force` re-encodes even `completed` list). Yoast's `test-helper` notion → WPPO `wp wppo image status --format=count` quick probe. |

**Config API principles to adopt:**
1. Single source for defaults: `Main::__construct():240-265` + `WPPO_CLI_Command::get_default_settings():451-522` divergence (CLImissing 7 tabs `litespeed_integration,llms_txt,od_integration,bfcache,perf_translations,ai_adaptive,edge_cache`) must converge via `Settings_Service::defaults()` (see `ARCH-RESEARCH.md §2`).
2. Allowlist: `ALLOWED_SETTINGS_KEYS:Util:43` + `ALLOWED_SETTINGS_TABS:Util:59` single source — both CLI `import:644` + `update:728` + REST `update_settings:470` already use it; keep.
3. Sensitive redaction on export (password, api key) — WPPO already at `export:593-599`; keep and document “export never contains secrets; import strips them”.
4. File vs stdout: `export --file` writes via `WP_Filesystem::put_contents` (`:610`); without `--file`, `log(json)` to STDOUT pipeable to `jq`. LiteSpeed's `export --filename` alias and `import_remote <URL>` fetch via `wp_remote_get` + temp file could be added later.
5. Reset: `wp wppo settings reset --yes` factory defaults (not yet exists) — add when presets land, gated `confirm` + `delete_option('wppo_settings')` + `update_option` with defaults.

---

### 4.3 Extension API — Service Delegation, Thin Commands, No Logic in CLI

| Ecosystem | Extension pattern | Why it applies |
|-----------|-------------------|----------------|
| WooCommerce `WC_Container` + REST behind CLI | `wp wc product list --user=1 --format=json` is thin: command validates args → calls `WC_REST_Products_Controller::get_items($request)` → formatter renders. Logic lives in controller/service, not command. | WPPO anti-pattern today: `image convert:321-367` does `realpath` loop + `convert_image` inline; `settings export/import:590-676` does FS `exists/put_contents + json_decode + allowlist + sanitize` inline (see `ARCH-RESEARCH.md §3` violations). **Fix:** delegate to `Image_Service::convert_batch()`, `Settings_Service::export($file)`, etc. so REST + cron + CLI share one path (gap `@WP-CLI-CURRENT §3.3/3.4`). Tests can mock service via Brain Monkey. |
| WP Rocket ServiceProvider / LiteSpeed `LSCWP_CTRL` split | Rocket uses `ServiceProvider` + `Container::get(Cache_Subscriber::class)` per feature; each feature `get_subscribed_events()` self-registers hooks; CLI `WPRocket_CLI` only calls helpers (`rocket_clean_domain()`, `rocket_clean_minify()`) not business logic | Validates `ARCH-RESEARCH.md §5-6` recommendation: keep `setup_hooks()` explicit (`add_action` grep-able) but extract services. LiteSpeed keeps `if(WP_CLI) require_once Cli.php` outside setup — WPPO already `Main::includes:472` same guard — keep. |
| WP-CLI handbook `WP_CLI::add_command` closure vs class collection | Handbook cookbook: class-per-command or `Class as collection` with `@subcommand` per method (`WP_CLI::add_command('wppo', Class)`). | WPPO uses collection (good). Future large family (`wp wppo rum`, `wp wppo edge`, `wp wppo llms`) keep within same class until ≥10 subcommands, then split into `WPPO_Cache_Command`, `WPPO_Settings_Command` etc. with same `wppo` root via `WP_CLI::add_command('wppo cache', Cache_Command)` leaf registration pattern (handbook `$args synopsis array`). |

**Extension API principles:**
- **Thin CLI, rich service:** command parses `$args/$assoc_args` → calls `WP_CLI::confirm`/`get_flag_value` → delegates to service → renders via `Formatter` (`ARCH-RESEARCH.md §2` table). Never duplicate allowlist logic between CLI and REST (`database TABLE_MAP:42` vs CLI `180`, `object-cache 6 vs 10 keys` at `class-wppo-cli-command.php:864-871` vs `class-rest.php:1104-1142` — converge via service constant).
- **REST/CLI parity:** every REST route should have a CLI analogue or documented absence (`WP-CLI-CURRENT.md §5` matrix: `rum`, `activities`, `autoloaded_options`, `used_css/ccss`, `cron health`, `suggestions/web_vitals_trends` gaps — close with subcommand or `wp help wppo` “not yet” note).
- **Events over forks:** agencies extend via `wppo_*` filters/actions, not CLI command forks. CLI respects `has_filter` before applying.

---

### 4.4 Developer Filters & Action Hooks — `wppo_*` Design for CLI/Extensibility

| Ecosystem | Hook / filter design | Pattern to borrow |
|-----------|----------------------|-------------------|
| LiteSpeed Cache `litespeed_can_optm`, `litespeed_can_cdn`, `litespeed_vary` etc. — `docs/litespeed-research.md` + `HOOK-AUDIT.md §15` 5 undoc converts (`wppo_filesize_limit_bytes:402`, `wppo_cron_discovery_limit:Cron:666`, `wppo_server_timing_enabled:Main:1252`, `wppo_skip_combine…` etc.) | LiteSpeed filters are **ecosystem-gated** (`has_filter('litespeed_can_optm')` before `apply_filters`) so coexistence is opt-in. WPPO already mimics at `Cache:402` `litespeed_can_optm`, `1353 litespeed_can_cdn`. | **Borrow guard:** every `wppo_*` public filter should `if (has_filter('wppo_…')) $val = apply_filters(…)` pattern so default path is filter-free (perf win `PERF-RESEARCH.md §2.2` small). Document 15 undoc at `HOOK-AUDIT.md §15` in `docs/hooks.md` before adding new gaps. |
| WPPO `docs/hooks.md:1-439` 42 public `wppo_*` (8 actions + 34 filters), `HOOK-AUDIT.md:0` 272 hits (78 apply + 22 do) — `@since NEXT` versioning per `AGENTS.md:79` “Never invent version” | Current naming: `wppo_before/after_cache_clear`, `wppo_cache_page_html`, `wppo_exclude_{delay,defer,minification}`, `wppo_litespeed_*` 12, `wppo_llms_txt_*`, `wppo_od_*`, `wppo_bfcache_*`, `wppo_perf_translations_*`, `wppo_ai_adaptive_*`, `wppo_edge_cache_*` | **Keep prefix `wppo_`** (no collision), **use `wppo_cli_*` only for CLI-only hooks** (`wppo_cli_redis_config:G-22` etc.). For shared hooks keep generic `wppo_<noun>_<verb>` (`wppo_should_cache_request:G-01` at `Cache:1505`). Add `@since NEXT` to every new `apply_filters/do_action` (`AGENTS.md:79`). Mark internal cron hooks (`wppo_convert_image_background`) as `Private — not public API` in docs. |
| Yoast `wpseo_title`, `wpseo_metadesc`, `wpseo_canonical`, `wpseo_breadcrumb_*`, `wpseo_sitemap_*`, `wpseo_schema_*` + Surfaces (`Yoast\WP\SEO\Surfaces`) + Presenters (`Abstract_Indexable_Presenter`) | Yoast exposes **small, stable, versioned surfaces** not raw internals; filters take (`$value, $context`) with well-typed PHPDoc; deprecations via `_deprecated_hook()` warner | **Borrow:** expose typed service accessor for filters: `apply_filters('wppo_should_convert_image', true, $src, $fmt)` at `Img_Converter:319` with `string $fmt` documented; avoid leaking `$options` blob — pass contextual scalar after extraction. Add `_deprecated_hook` path when renaming (`wppo_litespeed_enable_nextgen_rewrite` → `wppo_litespeed_nextgen_rewrite` legacy alias at `docs/hooks.md:225-227` shows correct pattern). |
| WooCommerce `woocommerce_*` filters per resource (e.g. `woocommerce_product_get_price`, `woocommerce_order_status_*`) + `wc_get_container()->get(Class::class)` DI | Woo's `Settings API` + `Container` lets extensions replace a service → CLI automatically sees new behavior because CLI delegates to service | **Borrow:** when `Settings_Service`/`Cache_Service` extracted (`ARCH-RESEARCH.md §2`), extensions filter at service level (`wppo_settings_sanitize/G-20` etc.) so `wp wppo settings update` and `POST /update_settings` benefit together. |
| WP-CLI handbook `has_filter` + `apply_filters` naming `_deprecated_hook` + `--debug` groups | Handbook `--debug=<group>` custom groups: `if(Utils\get_flag_value($assoc,'debug')) WP_CLI::debug("msg", 'wppo')` | Add `WP_CLI::debug("Skipping $path outside ABSPATH",'wppo')` in `image convert` path safety branch at `:353-355` (currently silent `continue`) — visible only with `--debug=wppo`. |
| Object Cache Pro filters (`objectcache_*`) optional PSR-3 logger injection | Tailored, workload-specific filters (`$key`, `$group`, `$value`) plus logger | WPPO `wppo_debug_log:Cache:282` already internal; expose `wppo_cache_key` if agencies need custom TTL mapping (like LiteSpeed `wppo_litespeed_ttl:174`). |

**Developer contract for new `wppo_*`:**
1. Prefix `wppo_` (CLI-only: `wppo_cli_`); lowercase snake; single `apply_filters`/`do_action` per figure with `@since NEXT` + `@param` typed doc.
2. Document in `docs/hooks.md` before release (close `HOOK-AUDIT §15` 15-gap drift first — PR-Z in `ARCH-RESEARCH.md §8`).
3. Default must be **no-op** so `has_filter` short-circuit keeps perf (`PERF-RESEARCH.md §2.2`).
4. Add `wppo_enable_context_fences` escape hatch already recommended at `PERF-RESEARCH.md §8` — host can `add_filter('wppo_enable_context_fences','__return_false')` to rollback fences.
5. Keep `litespeed_can_*` / `litespeed_vary` as **consumed** external filters (never `do`), and `wppo_litespeed_*` as owned.

---

### 4.5 Cross-Cutting Principles — What to Borrow, What to Skip

| Principle | Borrow? | Why |
|-----------|---------|-----|
| WP Rocket `make_progress_bar` per batch + `WP_CLI::confirm + --confirm` alias | **Borrow** | Highest UX ROI for long loops (`image convert`, `database cleanup`, `cache status` walk). See 4.1.5–4.1.6. |
| LiteSpeed `litespeed-option get/set/all --format + export/import + presets + crawler/image/online` family split by concern | **Borrow selectively** | Keep single `wppo` root; add subcommands only when feature ships (`used-css`, `llms`, `edge`) — don't pre-split 8 families without need. |
| LiteSpeed `litespeed-purge` purge granularity (`all/url/blog/category/tag/post_id`) + `network_list` colorized table + `blog <id>` | **Borrow purge granularity** | WPPO `cache clear --page=<url>` is single-page only; add `--post_id` / `--url` / `--network` sweep when multisite demand appears. Use LiteSpeed's `WP_CLI::line` + `colorize` for `network_list`. |
| LiteSpeed `litespeed-database clear_posts/clear_comments/... [blog <id>]` per-type without `--type=` | Alternative to WPPO's `--type=` enum | Keep `--type=` (more scriptable) but support positional alias (`revisions|drafts|…` already at `class-wppo-cli-command.php:237-271`) — no change. |
| WooCommerce `wp wc` REST bridging + `--user` + `--format table|json|csv|yaml|count` + `tool run` pattern | **Borrow `--format`/`--user` thinking** | REST-bridged CLI ensures UI↔CLI parity; WPPO's `update_settings` REST (`class-rest.php:464`) + CLI `settings update` (`:707`) manual parity is drift risk — use shared `Settings_Service`. `--user` not needed (CLI trusted) but document why. |
| WooCommerce `Container` (`league/container`) | **Skip heavy** | `ARCH-RESEARCH.md §9` says avoid `league/container` dep (release ZIP bloat `scripts/build-release.sh`). Use hand-rolled `Service_Locator` lazy `class_exists(…,true)` (PSR-4 `autoload.classmap` after `vendor/autoload.php`). |
| WP Rocket external package `wp package install wp-media/wp-rocket-cli` | **Skip** | Keep bundled (`Main::includes:472` guard). External package requires user install step — worse DX for shared-host `wp wppo` immediate use. |
| WP Rocket `ServiceProvider` subscriber bus | **Skip full bus** | 25-file plugin does not need `SubscriberInterface::get_subscribed_events()` indirection; keep explicit `add_action` in `setup_hooks()` (grep-able, WP-idiomatic). |
| Yoast Surfaces + Presenters abstraction | **Borrow spirit** | Expose 2–3 typed surfaces (`WPPO::settings()`, `WPPO::cache()`, `WPPO::images()`) not 20 presenters; keep `Util` stateless kernel (`class-util.php:145-157` memoed). |
| Yoast `wp yoast index` per-collection bars + `--reindex` + lazy-load | **Borrow bars + lazy** | WPPO `database cleanup` 9-type bars mirror Yoast's `posts/terms/archives` bars; lazy-load already not needed (WPPO's `Cron::img_convert_cron` discovers). |
| W3TC `flush <scope>` scope-enum | **Skip new verb** | Already covered by WPPO `cache clear` + `database cleanup --type=`. |
| WP Super Cache `enable/disable/flush/preload/status` 5-verb minimal | **Already aligned** | WPPO `cache {clear|preload|status}` + `object-cache {enable|disable|flush|status|ping}` mirrors — keep. |
| WP-CLI core `wp search-replace --dry-run/--precise/--recurse-objects/--skip-columns/--all-tables --network` | **Borrow `--dry-run`** | Canonical destructive preview (4.1.5); skip other search-replace flags not relevant. |

---

### 4.6 Gap Mapping — Which Competitive Pattern Closes Which WPPO Gap

| Competitive pattern | WPPO gap it closes (where in repo) | Priority |
|---------------------|-------------------------------------|----------|
| Rocket `confirm` + handbook `WP_CLI::confirm + --yes/--confirm` | `WP-CLI-CURRENT.md §2.2/§3.1-3.6` — zero `confirm` on destructive `cache clear` all, `database cleanup --type=all`, `object-cache disable`, `settings import` | **P0 — handbook correctness** (also `ECOSYSTEM-RESEARCH.md §1.4 #4`) |
| Handbook + search-replace `wp search-replace --dry-run` | Same destructive set + `database cleanup`, `cache clear`, `image convert`, `object-cache disable` — no preview counts | **P0** (`§1.4 #5`) |
| LiteSpeed `all --format` + Woo `format_items` `table|json|csv|yaml|count|ids` + handbook `format_items/Formatter` | `WP-CLI-CURRENT.md §2.3/§3.1{status} §3.2{counts} §3.3{image status} §3.5{status} §3.6{results} §3.7{system-info}` — no `--format`/`--fields` except `settings get json|yaml` at `:690` | **P0** (`§1.4 #3`) |
| Rocket + handbook `make_progress_bar` per batch | `image convert` 100, `database cleanup --type=all` 9×∞ loops, `cache status` 5000 walk (`AUDIT P-WP-03`), `database optimize` locks, `cache preload` 500 sitemap | **P1 — parity** (`§1.4 #6`) |
| LiteSpeed `litespeed-purge blog <id> + network_list` + Rocket `clean --blog_id` + handbook `--url/--network` multisite | `WP-CLI-CURRENT.md §2.4/§5`, `ECOSYSTEM-RESEARCH.md §1.2 #7` — no `--network`/`--blog_id`/`switch_to_blog`; relies undocumented on global `--url` | **P1** (`§1.4 #7`) — document plus add sweep |
| Handbook `cache flush` all-sites warning | `object-cache flush:843` `Cache::clear_cache` all-makes | **P1** — add warning in help |
| DeliciousBrains ` --batch-size=500 default` + `make_progress_bar` + `wp_cache_flush` per batch | `image batch 50:330`, `database LIMIT 1000:138`, `preload batch 200:283` — service batching exists but CLI has no `[--batch-size]` knob | **P1** (`§1.4 #8`) |
| Handbook synopsis `<action>` required vs `[<action>]` optional + `--- options:` pre-invoke validation | `WP-CLI-CURRENT.md §3.1-3.6` docblocks mark `<action>` required but code defaults `?? 'clear'/'cleanup'/'status'` — misstates required + post-invoke errors instead of pre-invoke `Invalid value specified for '--type'` | **Must-fix** (`§1.4 #1-2`) |
| LiteSpeed `litespeed-option {set <key> <val>, get, all --format, export --filename, import, import_remote, reset}` + presets | WPPO 7-tab defaults divergence (`Main:240` vs `CLI:451` missing 7 tabs), export file handling, no `reset`/presets/`import_remote` | **P2** — converge via `Settings_Service::defaults()` single source (`ARCH-RESEARCH.md §2` Settings_Service), add `import_remote` only if migration demand |
| Yoast per-collection progress + Woo extensible surfaces | Missing `rum/activities/autoloaded_options/used_css/ccss/cron health/suggestions/web_vitals_trends` subcommands (matrix `WP-CLI-CURRENT.md §5`) | **P2** — close UI gaps `§1.4 #11` or document absence in `wp help wppo` |
| Handbook `@when after_wp_load` ignored for plugin-loaded | `@when after_wp_load` correct but redundant — document why | **Doc-only** |

---

### 4.7 Sources (Prioritised Primary → Secondary)

| Pri | URL | What it governs |
|-----|-----|-----------------|
| Primary | https://make.wordpress.org/cli/handbook/guides/commands-cookbook/ + /references/argument-syntax/ + /references/documentation-standards/ + /references/exit-codes/ + /references/config/ + /references/internal-api/wp-cli-add-command/ + /references/internal-api/wp-cli-{success,error,warning,confirm,log,utils-make-progress-bar,utils-format-items}/ | Docblock→synopsis, `@when/@subcommand`, help shape, exit codes, `--format`, `--yes`, `confirm`, `make_progress_bar`, bootstrap |
| Primary | https://developer.wordpress.org/cli/commands/cache/flush/ + /search-replace/ + /cache/ + /transient/ | `cache flush` all-sites warning, `search-replace --dry-run/--precise/--recurse-objects`, verb shapes |
| Primary | https://docs.litespeedtech.com/lscache/lscwp/cli/ | 8 families `litespeed-option/purge/presets/image/online/debug/crawler/database`, synopses `set <key> <val>`, `purge network_list/all/url/blog/category/tag/post_id`, `crawler {list,enable,disable,run,reset}`, `image {push,pull,status,clean,rm_bkup,batch_switch}`, `database clear_posts [blog <id>]`, `online {init,sync --format,nodes}` + `--format table\|json\|csv\|yaml\|ids\|count` everywhere except `litespeed-database` |
| Secondary | https://docs.wp-rocket.me/article/1497-wp-cli-interface-for-wp-rocket + https://github.com/wp-media/wp-rocket-cli/blob/trunk/command.php (616 lines) | `wp rocket {clean/preload/regenerate/cdn/export/import/activate-cache}` + ` --post_id/--permalink/--lang/--blog_id --confirm` CSV + `make_progress_bar` per blog/lang + `WP_CLI::confirm` else branch + `wp package install wp-media/wp-rocket-cli` distribution |
| Secondary | https://developer.woocommerce.com/docs/wc-cli/cli-overview + /wc-cli-commands + wiki/WC-CLI-Overview + docs/wc-cli/using-wc-cli.md | `wp wc <resource> <action> --user=<id> --format=…` REST-bridged verbs `list/get/create/update/delete` + `tool run clear_transients` + `product create --<field>=<val>` splat |
| Secondary | https://github.com/wp-cli/wp-super-cache-cli (58★) + https://github.com/BoldGrid/w3-total-cache `Cli.php` | `wp super-cache {enable,disable,flush,preload,status}` 5 verbs; `wp w3-total-cache flush <scope>` + `cdn_purge` |
| Secondary | https://yoast.com/developer-blog/yoast-seo-wp-cli-index-command + https://developer.yoast.com | `wp yoast index` progress per collection (`posts/terms/archives/general` 100%[======] 0:00), lazy indexables fallback |
| Secondary | https://github.com/wp-cli/cache-command + https://github.com/wp-cli/search-replace-command + https://wp-cli.github.io/ | Bundled `wp cache {add,decr,delete,flush,flush-group,get,incr,patch,replace,set,supports,type}` + transient cache DB vs object cache; `search-replace --dry-run --network --precise` data-migration recipe |
| Context | `docs/research/wp-cli-hooks/WP-CLI-RESEARCH.md` §1-16 + `WP-CLI-CURRENT.md` §1-5 + `ARCH-RESEARCH.md` §2-6 + `PERF-RESEARCH.md` §1-7 + `HOOK-AUDIT.md` + `HOOK-GAPS.md` + `docs/hooks.md:1-439` | Internal gap matrices, 272-hit hook audit, context cost, file:line current-state evidence |

*Fetched 2026-08-28; cross-checked LSCWP 8-family TOC + Rocket `command.php:trunk` 616-line source + Woo `wc-cli-commands` via WebFetch/WebSearch. No proprietary internals reproduced — only documented synopsis & architecture patterns. Research-only, no production edits.*

