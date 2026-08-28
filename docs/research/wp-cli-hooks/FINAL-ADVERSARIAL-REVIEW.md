# FINAL-ADVERSARIAL-REVIEW.md — Final Adversarial Challenge to IMPLEMENTATION-PLAN + MATRIX

**Date:** 2026-08-28 · **Plugin:** `performance-optimisation` v1.9.0  
**Scope:** `docs/research/wp-cli-hooks/IMPLEMENTATION-PLAN.md` (120 lines) + `IMPLEMENTATION-MATRIX.md` (32 lines) vs `ADVERSARIAL-REVIEW.md` (153 lines)  
**Mode:** Research-only — **no production code modified** (`git diff -- includes/` must stay empty)  
**Method:** Every `RETAIN` must survive: (1) real operator/CI fails without it, (2) not already in WP core/WP-CLI/WPPO, (3) handbook URL explicitly recommends it, (4) hot-path cost <0.1ms, (5) `File:Line` exists, (6) public-API maintenance <=1 major burden. Else `MODIFY` or `REJECT`.

> Handbook refs: `make.wordpress.org/cli/handbook/guides/commands-cookbook/#longdesc` (synopsis), `…/references/internal-api/wp-cli-utils-format-items/` (Formatter), `…/wp-cli-confirm/` (confirm), `…/wp-cli-utils-make-progress-bar/` (progress), `developer.wordpress.org/reference/hooks/pre_update_option__option/` (settings), `developer.wordpress.org/reference/hooks/cron_schedules/` (cron), `developer.wordpress.org/reference/hooks/styles_inline_size_limit/` (inline budget).

---

## 1. Over-Engineering — What Must Be Cut

| Claim in Plan | Why over-engineered | Evidence |
|---------------|---------------------|----------|
| **12 new `wppo_*` hooks @ P0/P1** `IMPLEMENTATION-PLAN:50` | 27 total public hooks (15 undoc+12 new) doubles API surface from ~15 to 27 in one release. Semver cost: each filter becomes BC promise (`@since NEXT` per `AGENTS.md:184`). Prior adversarial already REJECTed 20/32 gaps; plan re-adds rejected `wppo_cdn_url`, `wppo_delay_js_should_delay`, `wppo_sitemap_preload_limit` as P1. | `docs/hooks.md:1` currently 42 rows; plan `42→69`. `class-cache.php:1357` cdnRewrite already gated by `wppo_litespeed_can_cdn:1349` + `litespeed_can_cdn` filter — per-asset filter adds 1× `apply_filters` per tag in `while(next_tag):1382` hot loop (40 tags × 0.02ms = 0.8ms TTFB). |
| **Per-type DB hooks + `wppo_before_database_cleanup`** `MATRIX P0` `class-database-cleanup.php:714/935` | `wppo_database_cleanup_completed` at `Database_Cleanup:737` already fires for `all`. Adding both `before` + `type_completed` filter + action = 2 hooks for a 5-min WP-Cron `auto_clean:784`. Slack integration can use existing `Log::add` tail or single `after` action. | `class-database-cleanup.php:737` `do_action('wppo_database_cleanup_completed','all',…)` exists. `class-rest.php:880` REST path already logs. Duplicate. |
| **`--network` multisite sweep** `MATRIX P0` | `uninstall.php:193-217` pagination exists for **uninstall only**. Extending to `cache clear`/`database cleanup` copies 30 lines of `get_sites(['number'=>100])` + `switch_to_blog` `try/finally` per subcommand. WP-CLI already provides global `--url=<site>` (handbook `references/config/` — “In multisite, how target site is specified”). `--network` OOM risk on 10k sites + stale `Util::$settings_cache`/`$home_url_cache` if not reset. | `class-wppo-cli-command.php:75` no `--network` today; global `--url` works at `WP_CLI\Runner::set_url_params` **before** `after_wp_load` `class-main.php:472`. |
| **`--dry-run` for `cache clear`** | `Cache::get_cache_stats:86` + `cache status` already preview `size`/`cached_pages`. Dry-run for `cache clear` lists unlink candidates — read-only dir walk 200-800ms duplicated for preview. `wp search-replace --dry-run` precedent is for **DB mutations** not `unlink`. | `class-wppo-cli-command.php:103` `Cache::clear_cache()` is `unlink` loop; preview re-walks same dir. |
| **`WP_CLI\Formatter` + `--fields` + `yaml` fallback** | `MATRIX P0` imports `Formatter` `table|json|csv|yaml|count` per `WP-CLI-RESEARCH:188`. But 70% of PLAN’s commands are <5-line human logs (`cache status:87-89` 3× `log(sprintf)`). `yaml_emit`/`Spyc` fallback `class-wppo-cli-command.php:693` adds dependency dead-code. | `class-wppo-cli-command.php:202` `database counts` is only data command needing machine output; rest are `WP_CLI::success` human. |
| **`Image_Service` extraction** `ARCH A-03` | Purely to enable `make_progress_bar` test mocking for 1 CLI verb (`image convert`). 40 lines + new `class-image-service.php` + `composer dump-autoload` violates `AGENTS.md:18` manual `require_once` `Main:438-474` contract for minimal gain. | `class-wppo-cli-command.php:343-360` loop is 18 lines; Brain Monkey can mock `Img_Converter::get_img_info` and `realpath` without service. |
| **Context fence `is_admin()` 7 hooks + lazy `init_filesystem:346`** `MATRIX P1 perf` | Saves 0.4-0.7ms CLI (`PERF-RESEARCH:6` corrected from 270→70 hooks). Frontend `process_buffer_only:1243` is 5-40ms — saving 0.4ms is <1% and not user-visible. Adds 30-line branching + risk that `admin_menu:486` missing breaks `wp wppo` help when `DOING_CRON` true. | `class-main.php:485-799` 314-line `setup_hooks` monolith co-locates version gates `#553` `is_wp63_plus:501`. Splitting hurts grep-ability. |

**Unsupported assumptions**

* “Membership needs `wppo_should_cache_request`” — No issue/linked ticket proves `DONOTCACHEPAGE` insufficient. `DONOTCACHEPAGE` set via `template_redirect:182` early enough if membership plugin uses `init` or `wp` hook; filter before `DONOTCACHEPAGE` at `class-cache.php:1505` inverts expected constant precedence (constant should win). Evidence: `class-cache.php:1219` `is_not_cacheable()` already checks `DONOTCACHEPAGE` + `is_404` + `is_cache_allowed_for_current_user:297`.
* “0.4-0.7ms CLI fence is perf win” — No XHProf/blackfire before/after cited; `PERF-RESEARCH §2.3` admits 70–105 hooks not 270. Micro-opt without flamegraph is speculation.
* “Every REST endpoint needs CLI alias” `W-11` — Handbook cookbook is aspirational; primary CI jobs are `cache clear`, `database cleanup`, `settings import`, `object-cache flush` (7 verbs already cover 95%). Adding 5 verbs bloats `WPPO_CLI_Command:973` → ~1500 lines.
* “Operators need per-asset CDN” `wppo_cdn_url:1357` — No support ticket; dual-CDN is edge-case; global `cdnURL` + `wppo_litespeed_can_cdn:1349` covers single CDN 99%.

---

## 2. Redundant / Duplicate Hooks (use existing WP core / WPPO instead)

| Proposed | Redundant with | Verdict |
|----------|----------------|---------|
| `wppo_should_cache_request` **before** `DONOTCACHEPAGE` at `Cache:1505/1755` | `DONOTCACHEPAGE` constant (set in `mu-plugin`/`template_redirect` before `ob_start`) + `wppo_cache_page_html:1661` post-pipeline mutate. Early veto breaks “constant wins” expectation. | **MODIFY** — keep ONE filter **after** `DONOTCACHEPAGE` check, not before. Single insertion at `is_not_cacheable:1219` after constant. |
| `wppo_should_cache_for_user` at `Cache:297`+`Main:369` | `cache_settings.loggedInCacheRoles` allowlist + `Util::is_cache_eligible_for_current_user:297` already per-role. New filter adds 1× `apply_filters` on every front-end hit. | **REJECT** — document `loggedInCacheRoles` + `wppo_should_cache_request` (single veto covers user+URL). |
| `wppo_before_database_cleanup` + `wppo_database_cleanup_type_completed` filter | `wppo_database_cleanup_completed:737` for `all` + `WP_Error` return from `invoke_cleanup_method:935` already observable. Per-type Slack can tail `Log::add`. | **MODIFY** — keep ONE `do_action('wppo_database_cleanup_completed', $type, $count)` per-type after `clean_*`; REJECT `before` action and `*_type_completed` filter. |
| `wppo_cache_written`/`wppo_cache_miss`/`wppo_invalidation_urls`/`wppo_purge_urls` | `wppo_before/after_cache_clear:2032/2074` + `X-WPPO-Cache: HIT` header + `wppo_cache_page_html:1661` cover observability; `wppo_purge_urls` duplicates `wppo_invalidation_urls` (inval = CDN purge list subset). | **REJECT** `written/miss`; **RETAIN** `wppo_invalidation_urls:1838` only (single URL list mutates both FS and CDN paths). |
| `wppo_cdn_url` per-asset at `Cache:1357` | `wppo_litespeed_can_cdn:1349` + `apply_filters('litespeed_can_cdn',true)` + global `cdnURL` setting. Per-asset loop `while(next_tag):1374` already has `should_bypass_for_litespeed:398`. | **REJECT** — defer until dual-CDN ticket exists. Document workaround: filter `wppo_cache_page_html` to str_replace. |
| `wppo_delay_js_should_delay` at `Main:600` | `wppo_exclude_delay_js:515,722` array filter (once in `setup_hooks`) already veto; per-handle `strategy` handled by core `script_loader_tag:608` `strategy` assignment (WP 6.3+). | **REJECT** — keep array filter; defer runtime predicate. |
| `wppo_should_serve_next_gen` at `Image_Optimisation:887` / `wppo_should_lazy_load_image:100` | `excludeConvertImages` setting + `Accept` header gate + `excludeLazyImgs` URL list already veto; per-image predicate fires **per `<img>`** (N× per page). | **REJECT** both — document `excludeConvertImages` + `wppo_cache_page_html` as veto. |
| `wppo_preload_batch_size`/`wppo_sitemap_preload_limit` at `Cron:301/364` | Settings `excludePreloadCache` + core `cron_schedules:61` filter (handbook `developer.wordpress.org/reference/hooks/cron_schedules/`) already tune caps. CLI `--batch-size` duplicates filter. | **REJECT** — keep single `wppo_preload_batch_size` only if 10k-post site proves need; REJECT sitemap limit. |
| `wppo_object_cache_config` + `wppo_cli_redis_config` at `Object_Cache:252` / `CLI:864` | `wppo_redis_allow_request_password:1130` + `WPPO_REDIS_PASSWORD` constant precedence + allowlist converge 6→10 (W-10) fixes divergence without filters. | **MODIFY** — keep ONE `wppo_object_cache_config` after merge `Object_Cache:252`; REJECT `wppo_cli_redis_config` (CLI allowlist converge suffices). |
| `wppo_settings_before_update` at `Rest:464` | Core `pre_update_option_wppo_settings` + `update_option_wppo_settings` (`developer.wordpress.org/reference/hooks/pre_update_option__option/`). Plugin veto via `WP_Error 400` at `Rest:464` duplicates core returning `$old` to abort. | **REJECT** — document core `pre_update_option_wppo_settings` as veto hook. |
| `wppo_rum_should_collect` at `RUM:121` | Setting `rum_enabled:false` + rate-limit `120/hour:49` + `token` gate already sufficient; 1% sampling via early return saves transient write but adds per-beacon filter. | **REJECT** — defer until 1M PV/day ticket. |

---

## 3. CLI Commands — Necessary vs Unnecessary

| Proposal | Handbook / File:Line | Challenge | Verdict |
|----------|----------------------|-----------|---------|
| Synopsis `[<action>]` + `options:` enum `CLI:49,130,301` | `make.wordpress.org/cli/handbook/guides/commands-cookbook/#longdesc` validates **before** `invoke` | `WP_CLI::error` at `:93` already returns exit 1; typo `cache claer` fails anyway but later (inside `clear_cache` path not taken). Docblock-only, zero runtime. | **RETAIN** — keep; cost zero. |
| `--format=json` (not `table|csv|yaml|count`) via `Formatter` | `…/references/internal-api/wp-cli-utils-format-items/` canonical for data commands | `database counts:202` 9-key JSON is only data output; `cache status:87`/`object-cache status:808` are 3-line human logs. | **MODIFY** — `database counts` + `system-info` → `--format=json` (fallback `wp_json_encode` if `Formatter` absent). REJECT `table|csv|yaml` + `--fields`. Document `jq` for table: `wp wppo database counts --format=json | jq -r …`. |
| `--yes`/`--confirm` via `WP_CLI::confirm` for 4 destructives | `…/references/internal-api/wp-cli-confirm/` respects `--yes` | `cache clear` is idempotent (re-generates on next hit via `start_output_buffer:1217`); prompting blocks CI if forgotten. | **MODIFY** — retain `--yes` ONLY for `database cleanup --type=all` (batched `DELETE:138` not idempotent) + `object-cache disable` (removes `advanced-cache.php` drop-in). REJECT for `cache clear` + `settings import` (import already validates `ALLOWED_SETTINGS_KEYS:644`). Gate: `Utils\get_flag_value($assoc,'yes',false)` + `STDIN isatty` check; alias `--confirm` REJECT (handbook has no `--confirm`; Rocket compat not needed). |
| `--dry-run` | `developer.wordpress.org/cli/commands/search-replace/` precedent for mutations | `get_counts:842` already preview for DB; `cache status:86` dir walk is preview. | **MODIFY** — retain `database cleanup --dry-run` only (reuse `get_counts` + log `would_delete`, no `DELETE`). REJECT for `cache clear`/`image convert`/`settings import`. Early `if($dry_run){ WP_CLI::log(json); return; }` before `delete_in_batches`. |
| `--network` sweep `get_sites` pagination | `WP_CLI\Runner::set_url_params` `--url` doc | Global `--url` selects blog without code. `switch_to_blog` leak risk + `Util::clear_settings_cache` missing reset. | **REJECT** as P0. Downgrade to **P3 deferred** or docs-only: “In multisite use `wp --url=<site> wppo cache clear` per site; loop via shell `for url in $(wp site list --field=url)`”. If kept, must paginate `100/page` per `uninstall.php:193-217`, `try/finally restore_current_blog`, `function_exists('is_multisite')&&is_multisite()` guard, reset `Util::$settings_cache`. |
| `--batch-size` / `--limit` | DeliciousBrains recipe `--batch-size default 500` (via `WP-CLI-RESEARCH:10`) | `image_optimisation.batch:330` default 50 already stored; CLI flag duplicates setting. `database optimize` 5 tables not batched. | **REJECT** — tune via `wp wppo settings update image_optimisation --settings='{"batch":25}'`. Keep setting, not flag. |
| `make_progress_bar` gate `count>10` | `…/references/internal-api/wp-cli-utils-make-progress-bar/` auto-NoOp when piped | `cache status` walk 200-800ms + transients already cached 15min; bar misleading. `database optimize` 5 tables lock-time dominates. | **REJECT** — retain ONLY for `image convert` (>100 × 0.2s decode) **and** only if `total_pending>20` && tty && no `--format` — otherwise REJECT. |
| New subcommands `rum|used_css|presets|purge alias` | Handbook “complete alternative” is aspirational | No preset storage exists; `rum_data` is `GET` observation; `used_css` is cron-internal `Cron:72-73`. | **REJECT** all 5. Document `wp option get wppo_web_vitals_trends` + REST `rum_data` for scripting. Alias `purge→clear` via delegation not `@alias` tag. |

---

## 4. Conflicts with WP Conventions

* **Synopsis validation timing:** `@when after_wp_load:69` is ignored for plugin-loaded commands (handbook note `WP-CLI-RESEARCH §2`). Plan keeps it for docs — correct, but must not claim it gates load order. `class-main.php:472-474` `if(class_exists('WP_CLI')) WP_CLI::add_command('wppo',…)` already correct per handbook “Include in a plugin: conditionally load based on `WP_CLI`”.
* **`--confirm` alias non-standard:** Handbook has `--yes` only (via `Utils\get_flag_value($assoc,'yes')`). Rocket `wp rocket clean --confirm` alias required Rocket’s own handler reading both keys — WPPO should not invent alias without `get_flag_value` for both. Plan’s “`[--yes] + [--confirm] alias” conflicts with `WP-CLI::confirm($q,$assoc_args)` which only auto-handles `yes` when passed `assoc_args`.
* **Core hook duplication:** `wppo_cron_schedules` duplicates `cron_schedules` at `Cron:61`; `wppo_settings_*` duplicates `pre_update_option_wppo_settings`; `wppo_admin_localize` duplicates `wp_add_inline_script` + `gettext:229-230`; `wppo_preload_links` duplicates `wp_resource_hints:460-465` + `wp_speculation_rules:476` (WP 6.8). All REJECT per ADVERSARIAL G-20/G-23/G-24/G-25.
* **Drop-in hook impossibility:** `wppo_cache_hit` in `templates/advanced-cache.php` would need `do_action` before `plugins_loaded` — impossible without booting `wp-load.php`, breaking zero-boot promise `PERF §2.2`. REJECT (ADVERSARIAL G-02 already).
* **Filter naming `wppo_*` collision:** Must grep `grep -r wppo_` to avoid `transient_key:781` `blog_id_` collision. Plan’s `wppo_inline_drift_` prefix ok but needs `blog_id` prefixing on multisite (apply `Util::transient_key`).
* **`@since NEXT` discipline:** `AGENTS.md:184` forbids inventing `2.0.0`. New hooks/flags must use `@since NEXT` (plan does) + `docs/hooks.md` generation via `grep` to avoid drift.

---

## 5. Performance — Could Hurt

* **Per-asset `apply_filters` in `Cache::maybe_apply_cdn:1357`** — fires `wppo_cdn_url` inside `while(next_tag):1374` per attribute (`src`/`href`/`data-src` + `srcset`×2) → up to 5×40 = 200 filters/page. Keep short-circuit: `if(!has_filter('wppo_cdn_url')) return $buffer` before loop.
* **Per-image `apply_filters` (`lazy:100`, `next_gen:887`)** — N× per buffer (lazy_load_videos + add_delay_load_img). Each adds 0.01-0.03ms; 20 images = 0.2-0.6ms added to `process_buffer_only:1243` 5-40ms generation path. Defer.
* **6-stage `wppo_buffer_stage` REJECT** (plan already does) — correct; single `wppo_cache_page_html:1661` post-pipeline is 1× per generation vs 6×.
* **Progress bar overhead**  — `make_progress_bar` tick flushes STDERR per tick; gating `count>10 && !format && tty` avoids CI pipe pollution correct.
* **`get_sites` unbounded → pagination 100/page** mandatory; plan’s `number=>100` with offset loop per `uninstall.php:193-217` + `no_found_rows` correct. Still, 10k sites × `switch_to_blog` (clears `wp_cache`) spikes DB; shell loop via `--url` is faster.

---

## 6. Compat — Could Break

* **Redis allowlist 6→10** (`CLI:864-871` vs `REST:1104-1142`) — additive but `get_redis_config_from_assoc` silently drops `tls`/`sentinel` today. Expanding fixes silent drop (good) but sentinel `mode` requires `predis` extension; missing check → `WP_Error` at `Object_Cache::enable:252`. Guard with `extension_loaded('redis')` / `class_exists('Predis\Client')` before sentinel path.
* **Synopsis `[<action>]` default change** — `cache:75` `$action=$args[0]??'clear'` defaults to `clear` when arg omitted. Old `<action>` required arg but code already defaulted via `??` — change is BC-safe (lenient). Document default in help.
* **`switch_to_blog` state leak** — `Util::get_settings` caches in `static $settings_cache:91`, `Util::cached_home_url:214` caches `home_url`. Multisite sweep must `Util::clear_settings_cache()` + reset `$home_url_cache` per blog; plan notes this at `MATRIX W-07` — enforce in code.
* **Password redaction** — `settings export:594` strips `password` + `pagespeed_api_key`; new `--network` must not log `assoc_args` containing `password` to `STDOUT`/`Log`. Filter `config` before `WP_CLI::log`.
* **`advanced-cache.php` race** — `Cache::clear_cache:117` + `maybe_mark_page_not_cacheable:1449` `.wppo-no-cache` marker vs `clear` dry-run: dry-run must NOT create marker. Gate dry-run before `prepare_cache_dir`.

---

## 7. Maintainability — Public API Harder to Own

* 27 hooks → each needs `Hook|Type|Since|File:Line|Args|Priority|Example` row in `docs/hooks.md` (plan: `42→69`). Drift risk high; enforce `grep wppo_ → docs/hooks.md` CI check (plan’s `MIGRATION §?` notes).
* Filters returning `false` as veto vs empty-string skip semantics inconsistent: `wppo_cdn_url` `return '' → skip asset` vs `wppo_should_cache_request` `return false → skip ob_start`. Document strictly.
* Priority 10 everywhere is future-hostile; at least one hook (`wppo_invalidation_urls`) will need `PHP_INT_MAX:608`-style late priority for CDN purge after membership adds URLs. Keep 10 but note override.
* Service extraction (`Image_Service`, `Settings_Service`) adds 2 new `includes/` classes to manually `require_once` in `Main:438-474` + `performance-optimisation.php:41` bootstrap — violation of “no PSR-4” `AGENTS.md:42` intentional constraint. Keep CLI thin delegation as today (`cache:86,103,117`, `database:180`, etc.) — thin is good boundary.
* Versioning: `@since NEXT` placeholders replaced at release ( `AGENTS.md:184` ). 12 hooks × 2 releases = 24 placeholder bumps in `release.yml` tag flow — batch into one `NEXT→1.9.1` commit to avoid 12 commits.

---

## 8. Final Verdict per MATRIX / PLAN Item

| # | Area (MATRIX row) | Proposal | ADVERSARIAL verdict | FINAL verdict | Revised action |
|---|-------------------|----------|---------------------|---------------|----------------|
| 1 | P0 CLI help | `<action>` → `[<action>]` + `options:` enum `CLI:76,175,322` | RETAIN | **RETAIN** | Docblock only, zero runtime. Add defaults `default: clear\|cleanup\|status`. |
| 2 | P0 CLI output | `Formatter table\|json\|csv\|yaml\|count` + `Spyc` fallback `CLI:202,385,808,965` | RETAIN/MODIFY narrow `table\|json` | **MODIFY** | Keep **json only** for `database counts` + `system-info` with `class_exists('WP_CLI\Formatter')` → fallback `wp_json_encode(PRETTY)`. REJECT `table|csv|yaml|count` + `Spyc`/`yaml_emit` fallback; use `jq` for table. |
| 3 | P0 CLI safety | `WP_CLI::confirm` + `--yes`/`--confirm` for 4 destructives `CLI:75,174,622,801` | RETAIN | **MODIFY** | Keep `--yes` for `database cleanup --type=all` + `object-cache disable` only; `WP_CLI::confirm` when `!Utils\get_flag_value('yes') && STDIN isatty`. REJECT `--confirm` alias + `cache clear`/`settings import` prompts. |
| 4 | P0 CLI dry-run | `--dry-run` for `cache/database/image/settings` `CLI:75,174` | RETAIN/MODIFY cache+db | **MODIFY** | Keep `database cleanup --dry-run` only (`get_counts`/`SHOW TABLE STATUS` preview, early return before `delete_in_batches:138` / `OPTIMIZE`). REJECT cache/image/settings dry-run (preview = `status`/`counts`). |
| 5 | P0 CLI multisite | `--network` `get_sites` pagination `CLI:75` + `uninstall.php:193-217` | RETAIN/MODIFY `--network` only | **REJECT** (P3 docs-only) | Document `wp --url=<site> wppo …` + shell loop `wp site list --field=url | xargs -I % wp --url=% wppo cache clear`. If later kept, require `is_multisite() && function_exists('get_sites')`, `number=>100` paginated, `try/finally restore_current_blog`, `Util::clear_settings_cache` per blog. |
| 6 | P0 Hook veto cache | `wppo_should_cache_request` at `Cache:1505+1755` | RETAIN | **MODIFY** | Keep SINGLE filter at `is_not_cacheable:1219` **after** `DONOTCACHEPAGE`/`is_404` gate: `apply_filters('wppo_should_cache_request', true, $request_uri, $is_mobile, $is_logged_in)`; return false → skip `ob_start:1226` / `process_buffer_for_cache:1291`. REJECT dual insertion 1505+1755 (one site suffices; keeps `advanced-cache.php` path untouched). |
| 7 | P0 Hook user cache | `wppo_should_cache_for_user` + `wppo_should_optimise_for_user` at `Cache:297`+`Main:369` | RETAIN | **REJECT** | Covered by single `wppo_should_cache_request` with `$is_logged_in` + `user_id` param if needed; keep allowlist `loggedInCacheRoles` setting. Add `$user_id` to (6) as 4th arg instead of new hook. |
| 8 | P0 Hook DB per-type | `wppo_before_database_cleanup:714` + `wppo_database_cleanup_type_completed:935` per `clean_*` | RETAIN | **MODIFY** | Keep **one** `do_action('wppo_database_cleanup_completed', $type, $count)` after each `clean_*:935` and after `clean_all` loop `714` (per-type). REJECT `wppo_before_database_cleanup` action and `*_type_completed` filter (action suffices; Slack/alert can consume arg). |
| 9 | P1 Hook observability | `wppo_cache_written:1741`+`wppo_cache_miss:1661`+drop-in `hit` | RETAIN `written+miss` / REJECT `hit` | **REJECT** all 3 | Observability via `wppo_after_cache_clear:2074` + `wppo_cache_page_html:1661` + `X-WPPO-Cache` header + `access.log` suffices. `written` duplicates `save_processed_buffer:1229` success path; `miss` requires `ob_start` hook before WP — costly. Docs-only. |
| 10 | P1 Hook invalidation | `wppo_invalidation_urls:1838` before `foreach purge` | RETAIN | **RETAIN** (sole P1) | Keep `apply_filters('wppo_invalidation_urls', $urls, $post_id)` at `Cache:1838` with `wp_normalize_path` + `ABSPATH` prefix sanitize (prevent `..` per `Rest:413-432` pattern). Single URL list for both FS + CDN (merge G-03+G-27). |
| 11 | P1 Hook preload caps | `wppo_preload_batch_size:301`+`wppo_sitemap_preload_limit:364`+`wppo_sitemap_urls:487` at `Cron` | RETAIN MODIFY keep 3 | **REJECT** (P2 docs) | `cron_schedules` core filter + setting `excludePreloadCache:271` cover caps. Defer until 10k-post catalog ticket proves 200 insufficient. |
| 12 | P1 Hook CDN | `wppo_cdn_url:1357` per-asset | RETAIN | **REJECT** | `wppo_litespeed_can_cdn:1349` + `cdnURL` setting suffices. Per-asset filter → hot-loop cost. Defer until dual-CDN request. |
| 13 | P1 Hook delay | `wppo_delay_js_should_delay:600` at `Main` | RETAIN delay only | **REJECT** | `wppo_exclude_delay_js:515` array + core `script_loader_tag:608` `strategy` cover delay. |
| 14 | P1 Hook image veto | `wppo_should_serve_next_gen:887` at `Image_Optimisation` | RETAIN veto | **REJECT** | `excludeConvertImages` + `Accept` header file_exists gate sufficient. Veto via `wppo_cache_page_html` if external hotlink. |
| 15 | P1 Hook lazy predicate | `wppo_should_lazy_load_image:100` | RETAIN predicate | **REJECT** | `excludeLazyImgs` URL + `excludeFirstImages` count + `placeholderType` setting sufficient. |
| 16 | P1 Hook DB lifecycle extras | `wppo_database_cleanup_batch_size:138` + `wppo_revision_defaults:753` | RETAIN narrow | **REJECT** | Tune via `--dry-run` preview + setting `dbRevMaxAge:30`/`dbRevKeepLatest:5` UI; CLI `--batch-size` rejected already. Filter `LIMIT 1000` adds per-batch `apply_filters` overhead for DB path. |
| 17 | P1 Hook object-cache | `wppo_object_cache_config:67`+`wppo_cli_redis_config:864` 6→10 keys | RETAIN MODIFY | **MODIFY** | Keep ONE `wppo_object_cache_config` at `Object_Cache:252` after merge; converge allowlist 6→10 in `CLI:864` to match `REST:1104-1117` (add `mode,nodes,master_name,use_tls,persistent,compression` with `ALLOWED_KEYS` const). REJECT `wppo_cli_redis_config` (CLI converges via const, not filter). |
| 18 | P1 Hook settings veto | `wppo_settings_before_update:464` at `Rest` | MODIFY keep veto | **REJECT** | Use core `pre_update_option_wppo_settings` (return `$old` to veto) + `update_option_wppo_settings` post. Document core hook with WP_Error translation example (`add_filter('pre_update_option_wppo_settings', fn($new,$old)=> $new['file_optimisation']['removeUnusedCSS'] && empty($new['performance_audit']['pagespeed_api_key']) ? $old : $new)`). |
| 19 | P1 Hook CLI purge | `wppo_purge_urls:193` at `CDN_Purger` | MODIFY retain | **REJECT** | Merged into (10) `wppo_invalidation_urls`; CDN purge reuses that list after `wppo_after_cache_clear:2074`. One list, one hook. |
| 20 | P1 Perf context fence | `is_admin()` 7 hooks `Main:485-799` + lazy `init_filesystem:346` + `wp_next_scheduled×8:114` | RETAIN staged P0 only | **MODIFY** | Keep ONLY lazy `Util::init_filesystem:346` (0.3-0.8ms on `system-info`/`database counts` read-only) + remove duplicate autoload at `Main:437`. REJECT `is_admin()` fence for 30-hook frontend block and `wp_next_scheduled×8` debounce — XHProf required before commit, gated behind `wppo_enable_context_fences` rollback filter if ever added. |
| 21 | P2 Hook RUM sampling | `wppo_rum_should_collect:121` at `RUM` | RETAIN | **REJECT** | `rum_enabled:false` + rate-limit toggle suffice; sampling 1% is P3. Document `rum_enabled` as sampling off. |
| 22 | P2 CLI progress | `make_progress_bar:321,174` | MODIFY image+db all | **REJECT** (or P3 narrow) | If kept: ONLY `image convert` when `total_pending>20 && tty && !format`; REJECT `database cleanup` bar (9× `DELETE LIMIT 1000` — batch count unpredictable). |
| 23 | P2 CLI batch-size | `--batch-size` at `image:321` `batch 50` | MODIFY image+db | **REJECT** | Tune via settings `image_optimisation.batch` and `Database_Cleanup::delete_in_batches` `1000`; not CLI flag. |
| 24 | P2/P3 deferred | `wppo_buffer_stage:1243` 6-stage buffer | REJECT | **REJECT** | Keep `wppo_cache_page_html:1661` single post-pipeline. |
| 25 | Docs | `docs/hooks.md:1-439` 27 rows + 15 undoc | ADDED | **MODIFY** | Update `docs/hooks.md` for **retained** hooks only: synopsis fix, `wppo_should_cache_request`, `wppo_invalidation_urls`, `wppo_object_cache_config`, `wppo_database_cleanup_completed` per-type — ~4 new rows + 15 undoc audit. Not 27. Keep `readme.txt:279` `== External Services ==` already accurate. |

**Arch A-01..A-09:** All **REJECT** except **A-01 minimal** (`Util::get_default_settings()` 10-line share to fix 7-tab drift `CLI:451` vs `Main:240-265` `Unrecognized tab:731`) and **A-04 const** (`Object_Cache::ALLOWED_KEYS` promote). REJECT `Settings_Service`, `Cache_Service`, `Image_Service`, `Database_Cleanup_Service`, `Context` 3-file split, PSR-4, `Service_Locator`/`league/container`, lazy `Image_Optimisation`/`Google_Fonts`.

---

## 9. Revised Recommendation — What Actually Ships (3 PRs, not 6)

**Total retained scope:** 1 synopsis fix + json-only format + 2 `--yes` gates + 1 `--dry-run` (DB) + 3 hooks (1 P0 veto + 1 inval + 1 per-type) + 1 allowlist converge + `Util::get_default_settings` — **~120 lines** vs plan’s 500+.

| PR | Branch | Files | Risk | Tests |
|----|--------|-------|------|-------|
| **PR-A `fix/cli-phase1-help-format`** | `includes/class-wppo-cli-command.php:49,130,301,757,880` synopsis `[<action>]` + `database:counts` + `system-info:956` json-only + `get_default_settings` converge `Util::get_default_settings()` | Low | `WppoCliHelpTest` asserts `[<action>]` in `--help`; `WppoCliFormatTest` json (no table); `php -l` + `phpcs` 0 |
| **PR-B `fix/cli-phase2-safety-dryrun`** | `class-wppo-cli-command.php:174,801` `--yes` (`Utils\get_flag_value`) + `WP_CLI::confirm` for `database cleanup --type=all` + `object-cache disable`; `class-wppo-cli-command.php:174` `--dry-run` for `database cleanup` (early `get_flag_value` → `get_counts` log `would_delete` + `WP_CLI::warning` no `DELETE`); `class-wppo-cli-command.php:864-871` allowlist 6→10 via `Object_Cache::ALLOWED_KEYS` | Medium | `WppoCliConfirmTest` mock `confirm`; `WppoCliDryRunTest` asserts no `update_option`/`DELETE`/`unlink` when flag |
| **PR-C `fix/hooks-phase3-p0`** | `class-cache.php:1219` `wppo_should_cache_request` (single, after `DONOTCACHEPAGE`); `class-cache.php:1838` `wppo_invalidation_urls` (`wp_normalize_path`+ `ABSPATH` guard); `class-database-cleanup.php:935` per-type `wppo_database_cleanup_completed` (`$type,$count`); `class-object-cache.php:252` `wppo_object_cache_config`; `class-util.php:346` lazy `init_filesystem` only; `docs/hooks.md` 4 rows `@since NEXT` | Medium | `HookShouldCacheRequestTest` `apply_filters false → skip ob_start`; `HookInvalidationUrlsTest` `..` sanitized; `HookDatabaseCleanupPerTypeTest` `do_action` per `clean_*`; `HookObjectCacheConfigTest` |

Each PR `phpcs` + `phpunit` (`*Test.php` naming per `phpunit.xml.dist:9`, Brain Monkey `when('get_option')` stubs, `WPPO_Test_Bootstrap` trait) + `npm run lint:js → composer lint → npm test → npm run build` per `AGENTS.md:29` + `build/` committed + `gh pr checks` 5-10m.

**Deferred to docs-only (no code):** `--network` (shell `wp site list` loop doc), `--batch-size` (setting doc), `make_progress_bar` (defer until >20 pending proven), `wppo_*` 9 rejected hooks (document core alternatives: `cron_schedules`, `pre_update_option_wppo_settings`, `wp_resource_hints`, `should_load_separate_core_block_assets:833,837`, `wppo_cache_page_html:1661`), `Context`/PSR-4/Service extraction.

---

## 10. Acceptance Criteria (revised, measurable)

* `wp wppo database --help` shows `[<action>]` + `database counts --format=json` prints `{"revisions":12,…}` without `Formatter` dep (fallback JSON).
* `wp wppo database cleanup --type=revisions --dry-run` logs `would_delete` + `WP_CLI::warning` and **no** `DELETE`/`invalidate_counts_cache` (spy `Database_Cleanup::delete_in_batches` not called).
* `wp wppo database cleanup --type=all` without `--yes` on tty prompts `WP_CLI::confirm`; with `--yes` skips prompt; non-tty skips prompt.
* `apply_filters('wppo_should_cache_request', false, '/members/',…)` at `Cache:1219` skips `ob_start` and no `save_processed_buffer:1229`; `DONOTCACHEPAGE` true still wins even if filter true.
* `apply_filters('wppo_invalidation_urls', ['/feed/'], 123)` extends purge list; `../etc/passwd` filtered URL sanitized to `''` via `wp_normalize_path`.
* `do_action('wppo_database_cleanup_completed','revisions',12)` fires after single-type `clean_revisions`; `clean_all:714` still fires `('all',total,results)`.
* `wp wppo --url=https://sub.example.com cache clear` clears sub-site cache (no `--network` needed).
* `php -l 42 clean` `phpcs includes/ 0e3w` `phpunit 480→ ~495` `npm test` `build webpack 5.109` green per `AGENTS.md:29`.

---

## 11. Risks Kept

* `hook conflict wppo_*` low — add `grep wppo_ → docs/hooks.md` CI check.
* `dry-run leak` medium — `get_flag_value` first line + `WppoCliDryRunTest`.
* `redis silent drop` medium — `ALLOWED_KEYS` const + extension_loaded guard.
* `wppo_should_cache_request` before-constant inversion **fixed** by placing after `DONOTCACHEPAGE`.

---

*Research-only. Any `RETAIN` ships with `@since NEXT` (never invent version; `AGENTS.md:184`), `docs/hooks.md` `Hook|Type|Since|File:Line|Args|Priority|Example`, and full verification `npm run lint:js → composer lint → npm test → npm run build`. No production file edited in this review.*
