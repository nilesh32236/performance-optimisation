# Migration Compatibility — WP-CLI / Hooks Architectural Changes

**Date:** 2026-08-28 · **Plugin:** `performance-optimisation` v1.9.0  
**Source files read (verbatim):** `readme.txt:1-10`, `composer.json:12-57`, `performance-optimisation.php:1-70`, `includes/class-main.php:1-799,1032-1131`, `includes/class-util.php:1-915`, `includes/class-cron.php:1-751`, `includes/class-cache.php:1-200`, `includes/class-wppo-cli-command.php:1-973`, `includes/class-activate.php:110-187`, `includes/class-deactivate.php:37-68`, `uninstall.php:1-217`, `docs/research/wp-cli-hooks/*` (7 files)  
**Mode:** Research-only — no production edits. Every claim cites `file:line`.  
**Purpose:** Pre-flight matrix for *every* proposed CLI/hook improvement so a migration PR never breaks: WP versions, PHP, single vs network, WP-CLI present/absent, cron/REST/admin/frontend, object cache, hosting (Apache / Nginx / LiteSpeed / OpenLiteSpeed), backward compat / `@since` / deprecation / alias.

> Companion docs (evidence, not repeated here): [`WP-CLI-CURRENT.md`](./WP-CLI-CURRENT.md) (7 subcommands, file:line), [`WP-CLI-RESEARCH.md`](./WP-CLI-RESEARCH.md) (16-dimension handbook matrix), [`HOOK-AUDIT.md`](./HOOK-AUDIT.md) (272 hits), [`HOOK-CORE-RESEARCH.md`](./HOOK-CORE-RESEARCH.md) (33 lifecycle verdicts), [`PERF-RESEARCH.md`](./PERF-RESEARCH.md) (context-aware cost), [`ECOSYSTEM-RESEARCH.md`](./ECOSYSTEM-RESEARCH.md) (aggregate), [`CURRENT-STATE.md`](./CURRENT-STATE.md) (stub).

---

## 1. Baseline Compatibility Envelope

| Dimension | Canonical truth | Evidence | Migration floor |
|-----------|-----------------|----------|----------------|
| **WordPress Requires at least** | `6.2` | `readme.txt:4` + `performance-optimisation.php:5` | No feature may assume ≥6.3 unless version-gated (`function_exists` / `version_compare`) |
| **Tested up to** | `7.1` | `readme.txt:6` + `performance-optimisation.php:7` | CI must matrix 6.2, 6.3, 6.5, 6.7, 6.8, 6.9, 7.0, 7.1 |
| **Stable tag / Version** | `1.9.0` | `readme.txt:7` / `performance-optimisation.php:8` / `WPPO_VERSION` `:37` | All new symbols get `@since NEXT` (per `AGENTS.md:79-80`) — do not invent `2.0.0` |
| **PHP Requires** | `8.2` | `readme.txt:5` + `composer.json:13` `"php": ">=8.2"` + `composer.json:55` `platform.php: 8.2` | No 8.1 polyfill needed; typed properties, `readonly`, `str_starts_with` safe. Verify `parallel-lint 8.2-8.5` per `psalm-wpcs-check.yml` |
| **Composer autoload** | `classmap includes/` — **no PSR-4 for plugin classes** | `composer.json:19-21` + `AGENTS.md:43` | New classes must be manually `require_once`'d in `Main::includes():436-474` or add PSR-4 as separate PR (C01) with `composer dump-autoload` + `scripts/build-release.sh` regression |
| **Vendor ignored** | `vendor/` gitignored, `composer.lock` tracked | `AGENTS.md:31-38` | `composer dev-setup --ignore-platform-reqs` locally; `composer install --no-dev --optimize-autoloader` in `release.yml` — changing `composer.json` needs lock verification |
| **Data stores** | `wppo_settings` (single serialized option), `wppo_img_info` (non-autoload), `wppo_activity_logs` table, transients (`wppo_*`), post meta, cache dirs | `includes/class-activate.php:328-339` (`dbDelta`), `class-util.php:145-157` (`get_settings`), `class-cache.php:40-41` (`CACHE_DIR`), `uninstall.php:28-66` | All migration must use `get_option('wppo_settings')` / `Util::get_settings()` (per-blog memo) — never raw `$wpdb->options` |

### 1.1 What "Tested up to 7.1" means for hooks

- WP 6.2 minimum keeps `WP_CLI` global, `is_multisite()`, `switch_to_blog()`/`restore_current_blog()`, `get_current_blog_id()`, `WP_Filesystem`, `rest_api_init`, `admin_init`, `init`, `cron_schedules`, `template_redirect` all stable since ≥4.6. **No WP 7.1 API is required by CLI** — CLI is `@when after_wp_load` (`class-wppo-cli-command.php:69,168,315`…) so WP already booted.
- WP 7.1 gates only matter for *frontend* branch that CLI shares bootstrap with (`wp_template_enhancement_output_buffer` 6.9+, `wp_should_output_buffer_template_for_enhancement`, `wp_script_add_data( strategy )` 6.3+, `is_readable` inline gate 7.0). Those are `function_exists` / `version_compare` guarded at `class-main.php:484-532,676,1047,1063,1082` — keep them.

---

## 2. Hosting / Server Variation Matrix (before drilling into each change)

| Variation | Detect how | CLI affected? | Hook affected? | Notes |
|-----------|------------|---------------|----------------|-------|
| **Apache** | `.htaccess` handler exists | No (CLI never touches `.htaccess`; `Main::on_settings_update:1081-1123` does) | `Htaccess_Handler::update_rules()` gated `enableServerRules` (`class-main.php:1087-1093`) | CLI `settings update file_optimisation --settings='{"enableServerRules":true}'` triggers same handler — must handle unwritable `.htaccess` as warning not fatal |
| **Nginx** | `Server_Rules::is_nginx()` | No | `Server_Rules` Nginx snippets emitted via `server_rules` REST (`class-rest.php:180-184`) | CLI could expose `wp wppo server-rules` — needs no FS guard |
| **LiteSpeed / OpenLiteSpeed** | `LiteSpeed_Integration::is_litespeed()` + `.htaccess` readable (both read `.htaccess`) + filters `litespeed_can_optm`, `litespeed_can_cdn` | Minor — CLI must respect `wppo_litespeed_can_cdn` (`class-cache.php:1349`) and `litespeed_can_optm` (`class-cache.php:402`) so CDN/optimiser bypass gates stay honored even from CLI-triggered `cache clear` → `wppo_after_cache_clear` → `LiteSpeed_Integration::init():796-798` | `Main::on_settings_update:1090-1122` logs restart hint when LiteSpeed + `enableServerRules` | OpenLiteSpeed needs restart to pick up `.htaccess` — CLI should `WP_CLI::warning('OpenLiteSpeed restart may be required')` not error |
| **Shared object cache (Redis/Memcached)** | `wp_using_ext_object_cache()` / `wp_cache_get` backend | Yes — transient keys collide without prefix | `Util::transient_key():781-790` prefixes `{blog_id}_` on multisite; drop-in `templates/object-cache.php:532` uses `blog_prefix` | Any new `set_transient` in CLI must use `Util::transient_key()` or `get_sites()+switch_to_blog` loop must prefix manually (see uninstall) |
| **No object cache (options table)** | default | Transients become `wp_options` rows (`_transient_*`) | Same prefix rule — `uninstall.php:125-130` deletes both | CLI `cache status` walks filesystem, not transients — no cache backend dependency |

---

## 3. Proposed Architectural Changes — Inventory

Each row is one atomic PR-sized change; matrix §4 covers per-axis compatibility.

| ID | Change (what) | Files / Lines | Current state | Proposed |
|----|---------------|---------------|---------------|----------|
| **C-WPCLI-01** | Correct `WP_CLI` constant guard / `WP_CLI` global exposure | `performance-optimisation.php:41-44`, `class-main.php:472-474`, `class-wppo-cli-command.php:12-14` | `if (defined('WP_CLI') && WP_CLI) \WP_CLI::add_command(...)` correct; class uses `use WP_CLI; use WP_CLI_Command;` (class_exists `WP_CLI_Command` provided by WP-CLI Phar) | Keep guard verbatim; add `class_exists('WP_CLI_Command')` fallback for non-WP-CLI lint (PSalm); do not add `global $WP_CLI` — handbook uses constant only. Add `wppo_is_cli()` helper gated `defined('WP_CLI')&&WP_CLI \|\| PHP_SAPI==='cli'` if context fence needed, `@since NEXT` |
| **C-WPCLI-02** | Synopsis required→optional fix + enum validation | `class-wppo-cli-command.php:49,131,301,757,880` (`<action>` but code `?? 'clear'` etc.) | Docblock says `<action>` required, code defaults → `wp help wppo cache` lies; only 3 assoc have `--- options:` | Change to `[<action>]` + `--- default: clear + options: clear,preload,status ---` etc. for 5 subcommands. No runtime change except help text + pre-invoke validation |
| **C-WPCLI-03** | Output — `--format`/`--fields` + `Formatter`/`format_items` | `class-wppo-cli-command.php:385,202,808,926,965` always JSON pretty via `wp_json_encode` | Add `[--format=<format>]` + `Formatter` for `cache status`, `database counts`, `image status`, `object-cache status`, `pagespeed results`, `system-info` | Must stay `table` default human, `json` machine; `--quiet` suppresses `log/success` but not `line/error` — JSON should use `WP_CLI::line` vs `::log` decision per matrix 4.5 |
| **C-WPCLI-04** | Progress `make_progress_bar` + `--batch-size`/`--limit` | `class-wppo-cli-command.php:343-360` (image 50), `class-database-cleanup.php:138-180` (DELETE LIMIT 1000), `class-cron.php:288` (sitemap 500) | No bar, no CLI knob — loops appear hung on 100-image batches | Add `make_progress_bar($msg,$total)` + `tick()/finish()` (auto-NoOp when piped) + `[--batch-size=<n>]` (`max(1,(int))` default from settings) |
| **C-WPCLI-05** | `--dry-run` preview + `WP_CLI::confirm` + `--yes`/`--confirm` | `class-wppo-cli-command.php` zero `confirm`/`dry-run` | Destructive `cache clear`, `database cleanup --type=all`, `object-cache disable`, `settings import` run without prompt | Add `WP_CLI::confirm($q,$assoc_args)` respecting `--yes` (also `--confirm` alias Rocket) + `[--dry-run]` flag that skips `update_option`/FS delete and prints counts |
| **C-MULTI-06** | `is_multisite()` branching + `--network` / `--url` / `--blog_id` sweep | `class-wppo-cli-command.php` never calls `is_multisite`/`get_current_blog_id` | Relies on WP-CLI `--url` to select blog; no sweep | Add help note "In multisite use `wp --url=<site> wppo …`; add `[--network]` for all sites + `[--blog_id=<list>]` CSV compat. Never call `is_multisite()` without `function_exists` guard |
| **C-MULTI-07** | `switch_to_blog()` loop + `restore_current_blog()` safety | `uninstall.php:190-217` canonical pattern (`get_sites number 100 offset`, `switch_to_blog`, `wppo_cleanup_site`, `restore_current_blog`, `do-while`) | CLI has none | New `--network` must use same paginated loop, capture `WP_Error` per blog, always `restore_current_blog` in `finally`; reset `Util::clear_settings_cache($blog_id)` + `Util::$home_url_cache` per iteration. Cap at `get_sites count` guard to avoid OOM |
| **C-TRANS-08** | `Util::transient_key()` prefix isolation + `--network` transient sweep | `class-util.php:781-790` (`is_multisite() ? blog_id_'_' . key : key` with `try/catch`), `class-cron.php:288,415` (`transient_key('wppo_preload_cron_lock')`), `uninstall.php:106-130` (`{blog_id}_` prefix + LIKE sweep) | Single-site returns bare key; multisite prefixes — CLI locks are blog-scoped but CLI never sets them explicitly | Any new CLI transients (`wppo_cli_lock`, `wppo_cache_write_*`) must call `transient_key()`; `--network` sweep must use `switch_to_blog` before `get_transient`/`set_transient` — never raw `_transient_` option LIKE without prefix |
| **C-FS-09** | `WP_Filesystem` via `Util::init_filesystem()` | `class-util.php:322-334` (`global $wp_filesystem; if(!function_exists('WP_Filesystem')) require ABSPATH.'wp-admin/includes/file.php'; return WP_Filesystem() ? $wp_filesystem : false`), consumed at `class-wppo-cli-command.php:603-607,629-632` (`settings export/import`), `class-main.php:346-349` eager init, `class-activate.php:257-268`, `class-deactivate.php:44-46` | CLI export/import already uses `$wp_filesystem->put_contents/get_contents`; CLI `cache status/clear`, `database cleanup` use `Cache::clear_cache` internal FS fallback | New CLI file writes must follow `init_filesystem()` → `global $wp_filesystem` → check `false` → `WP_CLI::error('Unable to init filesystem')` pattern; add `WP_Filesystem` `false` path for hosts where FTP credentials required; never use `file_put_contents` directly for `wp-content/cache/wppo` — use FS abstraction |
| **C-HOOK-10** | Context-aware hook fences (frontend-only vs admin-only vs CLI-free) | `class-main.php:485-799` registers ~70-85 hooks every request; `PERF-RESEARCH.md:89` proves ~60 registrations wasted on CLI (admin SPA, frontend buffer, RUM, speculation etc.) | Proposed fences P0+P10+P11+P12 (`if(is_admin())`, `!WP_CLI && !wp_doing_cron() && !REST_REQUEST`, lazy `new Image_Optimisation`, deferred `Cron::schedule_cron_jobs`) | Keep `Util::ensure_settings_cache_hook():239-248` + `switch_blog:248` + `WP_CLI::add_command:472` always-registered; fence only admin/frontend optimisers. See §5 |
| **C-HOOK-11** | New public filters/actions (`wppo_*`) + missing docs | `docs/hooks.md` documents 42 public `wppo_*`; `HOOK-AUDIT.md:15` flags ~15 undocumented (`wppo_filesize_limit_bytes`, `wppo_cron_discovery_limit`, `wppo_server_timing_enabled`, etc.) | Add `wppo_should_cache_request`, `wppo_buffer_processed`, `wppo_cache_written/miss`, `wppo_before_database_cleanup`, `wppo_database_cleanup_type_completed` + document all | New hook = `@since NEXT` + filter default preserves old behavior (`apply_filters('wppo_*', $default)`); never change existing hook arity |
| **C-ALIAS-12** | Deprecation aliases + `@since NEXT` policy | `class-wppo-cli-command.php:451-522` `get_default_settings` omits 7 tabs vs `class-main.php:240-265`; `get_redis_config_from_assoc:864-871` allowlists 6 of 10 REST keys; `database` aliases `drafts/trash/spam…` already at `class-wppo-cli-command.php:241-271` | Add missing tabs + full Redis allowlist + command aliases (`wppo cache purge` alias `clear`) | Alias via second `@subcommand` or `@alias` doc tag + `WP_CLI::warning('deprecated…')` for one minor, remove next major; `@since NEXT` on every new method/arg |

---

## 4. Per-Change Compatibility Deep Dive

For each ID, matrix lists axis → compatible? → risk → guard.

### 4.1 C-WPCLI-01 — `WP_CLI` Global / Constant

| Axis | Compat | Detail |
|------|--------|--------|
| **WP 6.2–7.1** | ✅ | `WP_CLI` constant defined by WP-CLI Phar before `plugins_loaded`; `performance-optimisation.php:41 new Main()` → `Main::includes():472` `if(defined('WP_CLI')&&WP_CLI)` — correct order per WP-CLI handbook "Include in a plugin" |
| **PHP 8.2+** | ✅ | `defined()` + `&&` short-circuit — no `WP_CLI` undefined notice under 8.2 strict |
| **Single site** | ✅ | Constant is global, not per-site |
| **Multisite single (`--url`)** | ✅ | Constant unaffected; `--url` selects blog via WP-CLI core `WP_CLI\Runner::set_url_params` before `after_wp_load` |
| **Multisite `--network`** | ✅ | Same — loop must not re-define constant; guard stays top-level |
| **WP-CLI present** | ✅ | Class `WP_CLI_Command` autoloaded by Phar; `use WP_CLI; use WP_CLI_Command;` at `class-wppo-cli-command.php:14-15` safe — PSR class_exists inside Phar |
| **WP-CLI absent** | ✅ | Guard `defined('WP_CLI') && WP_CLI` is `false`; `class-wppo-cli-command.php` is still `require_once`'d via `Main::includes():438-474` but its `extends WP_CLI_Command` triggers fatal if WP-CLI absent **unless** `class_exists('WP_CLI_Command', false)` not checked — **risk:** `WP_CLI_Command` missing → fatal. Mitigate: wrap CLI file include in same guard or add `if(!class_exists('WP_CLI_Command')) return;` at `class-wppo-cli-command.php:21` guard (already `if(!class_exists('WPPO_CLI_Command'))` — second guard needed: `if(!class_exists('WP_CLI_Command')) return;` before class). Keep `@since NEXT` for guard |
| **Cron** | ✅ | `WP_CLI` false during HTTP cron (`wp_doing_cron()` true, `WP_CLI` false); CLI never registered |
| **REST / Admin / Frontend** | ✅ | False — not registered |
| **Object cache** | — | N/A |
| **Apache / Nginx / LiteSpeed** | ✅ | Constant is PHP, not server |

**Backward compat / `@since`:** No breaking change. If adding `wppo_is_cli()` helper, `@since NEXT`, return `(defined('WP_CLI')&&WP_CLI) \|\| (PHP_SAPI==='cli' && defined('WP_CLI_RUN'))`. No deprecation.

**Verification:** `php -l class-wppo-cli-command.php` without WP-CLI on 8.2 — must not fatal. `wp wppo --help` on 6.2 + 7.1 both list 7 subcommands.

---

### 4.2 C-WPCLI-02 — Synopsis Required→Optional + Enum Validation

| Axis | Compat |
|------|--------|
| All axes | ✅ Zero runtime change except help text + WP-CLI pre-invoke validator. Changing `<action>` → `[<action>]` + `--- default: + options:` never breaks scripts that pass the arg — it only adds validation for typos (previously `WP_CLI::error` at runtime, now validator error before invoke, same exit 1). Add `wppo cache purge` alias via doc `@alias purge` (handbook `@alias` not official — use second method `purge()` that delegates to `cache()` + `WP_CLI::warning('deprecated, use clear')`) — keep `@since NEXT` on alias |

**Host variation:** none.

---

### 4.3 C-WPCLI-03 — Output `--format` / `--fields` / `Formatter`

| Axis | Compat | Risk |
|------|--------|------|
| **WP 6.2–7.1** | ✅ | `WP_CLI\Formatter` stable since WP-CLI 1.0; `WP_CLI\Utils\format_items` 0.23+ — present on all tested WP-CLI bundles (2.8+). Guard `class_exists('WP_CLI\Formatter')` → fallback to `wp_json_encode` |
| **PHP 8.2+** | ✅ | `Formatter` uses `array` types — 8.2 safe |
| **CLI present** | ✅ | Adds flags; default `table` preserves human output; `--format=json` returns machine `json` identical to current pretty JSON — **compat:** scripts parsing `cache status` 3-line `Cache size:` must migrate to `--format=json` (`{"size":"…","cached_pages":…}`); keep old `log` lines when `--format` omitted so no break |
| **CLI absent** | ✅ | No hook |
| **Multisite** | ✅ | Format is per-invocation, not per-site |
| **Object cache** | ✅ | No interaction |
| **LiteSpeed** | ✅ | No server interaction |

**Deprecation:** Current `log` plain lines (`cache status :87-89`) remain default `table` rows; document "parse with `--format=json` for scripting" — no breaking change. New `--field=<field>` follows `wp user get 12 --field=login` handbook single-field pattern — `@since NEXT`.

---

### 4.4 C-WPCLI-04 — Progress `make_progress_bar` + `--batch-size`

| Axis | Compat |
|------|--------|
| **WP** | ✅ `WP_CLI\Utils\make_progress_bar($msg,$count)` stable since 2016, auto-NoOp when piped (`Shell::isPiped()`) — no `--format` conflict |
| **PHP 8.2** | ✅ |
| **CLI** | ✅ Add only — non-piped shows bar, piped (`\| jq`) auto-disabled. `--batch-size` default from `image_optimisation.batch:330` (50) — `max(1,(int)$assoc['batch-size'])` per DB-migration recipe |
| **Non-CLI** | ✅ Never invoked (CLI file only) |
| **Multisite `--network`** | Loop per blog → `make_progress_bar('Processing sites', count($sites))` + inner per-blog bar — safe |
| **Hosting** | No interaction |

**Backward compat:** Adding a flag never breaks existing calls; bar suppressed when piped so scripts not polluted.

---

### 4.5 C-WPCLI-05 — `--dry-run` + `confirm` + `--yes`/`--confirm`

| Axis | Compat |
|------|--------|
| **WP handbook** | ✅ `search-replace --dry-run` + `WP_CLI::confirm($q,$assoc_args)` (`--yes` skips) are canonical. Rocket `wp rocket clean --confirm` alias proves `--confirm` compat |
| **CLI present** | `WP_CLI::confirm` writes to STDOUT, reads STDIN — hangs non-interactive shells without `--yes` → **gate:** `if ( ! \WP_CLI\Utils\get_flag_value($assoc_args,'yes') && ! get_flag_value($assoc_args,'confirm') ) WP_CLI::confirm(...)` — CI must use `--yes` |
| **CLI absent** | No hook |
| **All hosts** | Safe — dry-run path skips `update_option`, `WP_Filesystem::delete/put_contents`, `OPTIMIZE TABLE`, `as_enqueue_async_action` — only reads + `WP_CLI::log` preview |
| **Multisite** | Dry-run per blog in `--network` loop — print `Blog %d (%s): would delete %d` without committing |

**Deprecation:** No break — new flag only.

---

### 4.6 C-MULTI-06 — `is_multisite()` Branching + `--network` / `--url` / `--blog_id`

| Axis | Compat | Guard |
|------|--------|-------|
| **WP 6.2–7.1** | ✅ `is_multisite()` core since 3.0 | Must be guarded `if (function_exists('is_multisite') && is_multisite())` per `class-util.php:788` pattern (`try/catch`) — unit tests mock `is_multisite` via Brain Monkey `when()` |
| **PHP 8.2** | ✅ | `try { is_multisite() } catch(Throwable)` pattern already in `Util::transient_key:788` — replicate for CLI |
| **Single site** | ✅ `is_multisite()` → `false`; `--network` should `WP_CLI::error(' --network is only for multisite')` exit 1 — not fatal |
| **Multisite single (`wp --url=https://sub.example.com wppo cache clear`)** | ✅ `get_current_blog_id()` selected by Runner — no `switch_to_blog` needed | Document as primary usage; CLI delegates to site-scoped helpers (`Util::get_settings():91-105` blog-keyed, `Cache` domain dirs) |
| **Multisite network (`wp wppo cache clear --network`)** | Requires `switch_to_blog` loop — see C-MULTI-07 | Needs `function_exists('get_sites')` guard (WP 4.6+ — safe given 6.2 floor) |
| **WP-CLI absent** | Irrelevant — flag only in CLI file | No non-CLI impact |
| **Cron / REST / Admin / Frontend** | No impact — branching only inside CLI methods, not `setup_hooks()` | Do not add `is_multisite` to hot `Main::__construct` path beyond existing `Util::transient_key` |
| **Object cache (persistent)** | ✅ Must use `Util::transient_key()` per blog — otherwise `wppo_preload_cron_lock` collides (see C-TRANS-08) | |
| **Apache / Nginx / LiteSpeed** | ✅ No server tie |

**`@since` / alias:** `--network` `@since NEXT`. Keep `--blog_id=<comma-list>` as companion for Rocket compat (`wp rocket clean --blog_id=2,4`). Normalize via `array_map('intval', explode(',',$ids))`.

---

### 4.7 C-MULTI-07 — `switch_to_blog()` Loop + `restore_current_blog()`

| Axis | Compat | Risk / Guard |
|------|--------|--------------|
| **WP 6.2–7.1** | ✅ `switch_to_blog(int $id)` + `restore_current_blog()` stable since MU era | Must be paired — missing `restore` corrupts `$wpdb->prefix`, `$wp_filter`, `Util::$settings_cache`, `Util::$home_url_cache`. Use `try { switch_to_blog($id); … } finally { restore_current_blog(); }` |
| **PHP 8.2** | ✅ | No strict typed issue |
| **Single site** | Loop never runs (`is_multisite() false` → skip) | — |
| **Multisite single** | No loop — no switch | — |
| **Multisite `--network`** | ✅ Canonical pattern proven in `uninstall.php:193-217`: `get_sites(['number'=>100,'offset'=>…])` paginated → `switch_to_blog` → `wppo_cleanup_site` → `restore_current_blog` → `do-while $has_more`. CLI sweep must clone that pagination (not `get_sites()` unbounded — OOM on 10k-site network) | Cap `WP_CLI\Utils\make_progress_bar` + respect `--limit`, flush caches per batch (`wp_cache_flush_runtime`) |
| **WP-CLI present** | ✅ | Loop runs inside CLI method after `WP_CLI::success` batch start |
| **WP-CLI absent / Cron / REST / Admin / Frontend** | No switch — loop only inside CLI `--network` branch | Never add `switch_to_blog` to `Main::setup_hooks` hot path — keep CLI-only |
| **Object cache** | ⚠️ Critical: `switch_to_blog` does not switch persistent object cache group — but `Util::transient_key:788` (`blog_id_`) + drop-in `blog_prefix` does. CLI must also `Util::clear_settings_cache($blog_id)` after `update_option('wppo_settings')` per blog or hook `update_option_wppo_settings` will fire on wrong blog | Call `Util::clear_settings_cache()` + `Util::reset_cached_home_urls()` if helper (`class-util.php:112,227`) exists |
| **Apache / Nginx / LiteSpeed** | ✅ | No server tie, but `Advanced_Cache_Handler::create()` per blog writes same `advanced-cache.php` — last blog wins. For `--network` settings import, only re-bake drop-in once after loop, not per blog |
| **Transient isolation** | See C-TRANS-08 | `set_transient(Util::transient_key('…'))` per blog inside loop — correct |

**Unit test gap:** `tests/php/*` mocks `get_current_blog_id`, `switch_to_blog`, `restore_current_blog` via Brain Monkey — add `WPPO_CLI_MultisiteTest.php` (`*Test.php` discovery rule per `AGENTS.md:162`) using `ReflectionMethod` for private `switch_to_blog` helper.

**`@since` / deprecation:** `--network` `@since NEXT`. No deprecation — new capability, no alias needed.

---

### 4.8 C-TRANS-08 — `Util::transient_key()` Prefix Isolation

| Axis | Compat |
|------|--------|
| **WP 6.2–7.1** | ✅ `is_multisite()` guard at `class-util.php:781-790` (`function_exists` + `try/catch Throwable`) ensures single-site returns bare key (`wppo_cache_size`), multisite returns `"{blog_id}_wppo_cache_size"` |
| **PHP 8.2** | ✅ `try/catch (Throwable)` already handles Brain Monkey misconfigured stubs (`class-util.php:125,789`) |
| **Single site** | ✅ Bare key — existing data unchanged; no migration read required |
| **Multisite single** | ✅ Blog 2's `2_wppo_cache_size` isolated from blog 5's `5_wppo_cache_size` even with shared Redis/Memcached (`AGENTS.md:187-188`) |
| **Multisite `--network` sweep** | Must set/get transients **after** `switch_to_blog($id)` so `get_current_blog_id()` inside `transient_key()` resolves correctly. If reading without switching, must fabricate key manually as `$transient_prefix = is_multisite() ? $site->blog_id . '_' : ''` matching `uninstall.php:106` |
| **WP-CLI present / Non-CLI** | All contexts (cron `wppo_preload_cron_lock:288`, `wppo_img_convert_lock:88`, `wppo_web_vitals_rescan_lock:74` etc.) already use `transient_key()` — CLI must do same for any new lock (`wppo_cli_import_lock`, `wppo_cache_write_*`, `wppo_inline_drift_*` at `class-cache.php:160-175`) |
| **Object cache (persistent) vs options** | On miss, `get_transient` reads object cache first; with ext cache the key is namespaced via `wp_cache_get($transient_key, 'transient')` — prefix avoids cross-blog collision. Without ext cache transient stored as `_transient_{key}` option — `uninstall.php:125-130` `LIKE '_transient_{blog_id}_wppo_%'` + `'_transient_timeout_*'` sweep proves options-table fallthrough |
| **Hosting** | LiteSpeed/Redis host with persistent cache benefits most; no host-specific break |
| **Existing transients** | Clear via `delete_transient(Util::transient_key($key))` — never raw `delete_option('_transient_wppo_*')` else multisite pref wrong |

**Backward compat:** Single-site bare keys stay bare — no DB migration. New `--network` CLI that correctly prefixes is stricter (creates `N_wppo_*` where old single-site CLI would create `wppo_*` on the main site) — **compat note:** document that `--network` fixes isolation; single-site `--url` invocations keep old behavior.

**`@since`:** `Util::transient_key()` already `@since NEXT` (`class-util.php:779`) — no change. New CLI lock keys `@since NEXT`.

---

### 4.9 C-FS-09 — `WP_Filesystem` via `Util::init_filesystem()`

| Axis | Compat | Risk / Guard |
|------|--------|--------------|
| **WP 6.2–7.1** | ✅ `WP_Filesystem()` API stable; `ABSPATH . 'wp-admin/includes/file.php'` path guarded `wp_normalize_path` at `class-util.php:326` | Must `require_once wp_normalize_path(ABSPATH . 'wp-admin/includes/file.php')` only when `!function_exists('WP_Filesystem')` — current `Util::init_filesystem:325-328` correct; do not replace with direct `file_put_contents` |
| **PHP 8.2** | ✅ `global $wp_filesystem` typed `WP_Filesystem_Base\|false` — 8.2 deprecation for dynamic props not triggered because `global` |
| **Single / Multisite** | ✅ Filesystem is site-global (`WP_CONTENT_DIR . '/cache/wppo/'` `class-cache.php:40`; `WP_CONTENT_DIR . '/wppo/'` uploads) — not per-blog except `min_cache_dir` blog suffix (`class-util.php:682-683`). CLI on multisite `--url` writes to correct blog's min dir via `get_current_blog_id()` inside `min_cache_dir` — no switch needed beyond `--network` loop |
| **WP-CLI present** | CLI `settings export/import :603-632` already uses `Util::init_filesystem() → global $wp_filesystem → exists/put_contents/get_contents` — keep pattern. **Shared hosting risk:** `WP_Filesystem()` may return `false` (FTP credentials required) → `WP_CLI::error('Unable to initialize filesystem.')` at `class-wppo-cli-command.php:606` — correct. New `--file` writes for `cache status --file` must replicate same guard + `WP_CLI::warning('OpenLiteSpeed restart …')` companion if `LiteSpeed_Integration::is_litespeed()` |
| **WP-CLI absent / Cron / REST / Admin / Frontend** | Cron `mark_page_as_processed:612-623` also uses `Util::init_filesystem() + $wp_filesystem->exists/delete` — same guard. Frontend cache write uses `Cache::get_filesystem():347-353` (lazy) — keep lazy | Eager `Main::__construct:346-349` `Util::init_filesystem()` costs 0.3-0.8 ms per request (`PERF-RESEARCH.md:118-119`) — proposal to make lazy is compat-safe because every FS consumer re-checks `get_filesystem()`; see C-HOOK-10 |
| **Object cache** | No tie | |
| **Apache** | `WP_Filesystem_Direct` works | No issue |
| **Nginx / LiteSpeed** | Often `www-data` ownership; `is_writable($wp_config_path)` at `class-activate.php:276-277` shows pattern — CLI must `wp_is_writable(WP_CONTENT_DIR . '/cache')` pre-check? Use `WP_Filesystem::is_writable` not `is_writable` directly | LiteSpeed doc: restart needed after `.htaccess` — see 4.6/4.7 |
| **Security** | `realpath` + `ABSPATH` prefix check at `class-wppo-cli-command.php:353-355` + `class-cache.php:373-374` prevents `..` traversal — retain for any new `--path`/`--file` arg | Symlink guard proven `uninstall.php:149-175` `is_link` before `is_dir` |

**`@since`:** `Util::init_filesystem()` `@since 1.0.0` stable — no version change.

---

### 4.10 C-HOOK-10 — Context-Aware Hook Fences (Stop Loading Frontend/Admin Hooks on CLI/REST/Cron)

| Axis | Compat | Detail |
|------|--------|--------|
| **WP 6.2–7.1** | ✅ All fences use stable predicates: `is_admin()` (WP 2.5+), `wp_doing_cron()` (WP 4.8+), `wp_doing_ajax()` (WP 4.7+), `defined('REST_REQUEST')&&REST_REQUEST`, `defined('WP_CLI')&&WP_CLI` (WP-CLI 1.0+). Keep canonical OR `!is_admin() && !wp_doing_cron() && empty(REST_REQUEST) && !(defined('WP_CLI')&&WP_CLI) && !wp_doing_ajax()` as `Context::is_frontend_request()` helper `@since NEXT` |
| **PHP 8.2** | ✅ | No issue |
| **CLI (`WP_CLI`)** | ✅ Biggest win (`PERF-RESEARCH.md:89` ~60 registrations wasted). Fence frontend trio (`wp_enqueue_scripts`, `wp_head`, `wp_resource_hints`, `script_loader_tag`, `RUM`, `Llms`, `Bfcache`, `combine_css`, `minify_*`, `Asset_Manager`, etc.) inside `if (!WP_CLI && !REST_REQUEST && !wp_doing_cron() && !is_admin())` block at `class-main.php:485-799` — saves 0.3-0.6 ms per CLI invocation. **Must keep** outside fence: `WP_CLI::add_command:472`, `Util::ensure_settings_cache_hook:239-248` (`update_option_wppo_settings` + `switch_blog:248` + `delete_option_wppo_settings`), `LiteSpeed_Integration::init():796-798` (read-only), `wppo_after_cache_clear` consumers (`623,626`), AS queue listeners (`775,778,781`), `save_post` invalidators (`552,784`) — those must fire from CLI-triggered `update_option`/`save_post` |
| **Non-CLI (Cron)** | ✅ `wp_doing_cron()` true during HTTP spawner — fence treats as non-frontend, skips frontend hooks but keeps `Cron:57-74` 12 scheduler hooks + image/cleanup workers |
| **REST** | ✅ `REST_REQUEST` true — skip frontend buffers (`wp_template_enhancement_output_buffer:545-546`), speculation, RUM — but keep `rest_api_init:615` 25 routes. Ensure CLI-dispatched REST (not used today) still works |
| **Admin** | ✅ `is_admin()` true — keep `admin_menu:486`, `admin_init:487-491`, `admin_enqueue_scripts:494`, `Metabox:762`, `Abilities:765` — fence removes them from frontend/CLI/REST/Cron |
| **Frontend** | ✅ Gate is frontend-only true case — no change |
| **Object cache** | Must not fence `Util::ensure_settings_cache_hook:239-248` (`static $hooked` guard at `239`) — it registers `update_option_wppo_settings` + `switch_blog` once per request; needed for `Util::get_settings` blog-key isolation. Also keep `Util::$home_url_cache` (`class-util.php:87,752`) no-op `on_switch_blog:214-219` hook |
| **Apache / Nginx / LiteSpeed** | ✅ Fence is PHP predicate, not server-dependent. `LiteSpeed_Integration::init` stays always-loaded so `litespeed_can_optm`/`litespeed_can_cdn` gates remain correct even from CLI `cache clear` |
| **Multisite** | ✅ `switch_blog` hook must stay always-registered (`class-util.php:248`) — covered above |

**Backward compat / `@since`:** Every new helper (`Context::is_frontend_request()`, `wppo_enable_context_fences` filter per `PERF-RESEARCH.md:273` rollback switch) `@since NEXT`. Fenced hooks were *excess* registrations (never fired in that context) so removing them is non-breaking. Provide `apply_filters('wppo_enable_context_fences', true)` so hosts can `__return_false` in emergency without code change.

**Risk if not fenced:** CLI `wp wppo settings get` pays 0.7-1.6 ms bootstrap (`PERF-RESEARCH.md:120-121`) server cost but never uses frontend chain — not functional break, just waste. Risk *of* fencing: `WP_CLI` constant not yet defined when `new Main()` runs via `performance-optimisation.php:44` on Must-Use load order — keep `defined('WP_CLI') && WP_CLI` guards (short-circuit), record timing comment in `Main::includes()`.

---

### 4.11 C-HOOK-11 — New Public `wppo_*` Filters / Actions

| Axis | Compat |
|------|--------|
| All | New `apply_filters('wppo_*', $default)` or `do_action('wppo_*', …)` with default preserving old behavior → backward compatible. Document in `docs/hooks.md` (42 today → add ~15 undocumented `wppo_filesize_limit_bytes` `class-img-converter.php:402`, `wppo_cron_discovery_limit` `class-cron.php:666`, `wppo_server_timing_enabled` `class-main.php:1252` etc. per `HOOK-AUDIT.md:15`). Each new hook `@since NEXT`. Never change arity of existing (`wppo_after_cache_clear` 2 args `class-cache.php:2074`, `wppo_database_cleanup_completed` 3 args `class-database-cleanup.php:737`, `wppo_exclude_delay_js` 1 arg `class-main.php:722` etc.) |

---

### 4.12 C-ALIAS-12 — Deprecations & Aliases

| Pattern | Compat | Example |
|---------|--------|---------|
| **CLI action alias** (`purge` → `clear`) | Add method alias `public function purge()` that calls `cache(['clear'], $assoc)` + `WP_CLI::warning('wppo cache purge is deprecated, use clear')` — keep `@since 1.9.0` on canonical, `@since NEXT` on alias. WP-CLI `@alias` tag not supported — use delegation method instead | `wp wppo cache purge` works for 1 minor, warning emitted |
| **Settings tab rename** | If `litespeed_integration` → `edge_cache` later, add `if($tab==='litespeed') WP_CLI::warning + map to 'litespeed_integration'` — `@since NEXT`, `@deprecated NEXT` doc |
| **Filter rename** | Keep old `apply_filters('wppo_old', …)` + add new `apply_filters('wppo_new', $filtered_old)` chain, doc `@deprecated NEXT — use wppo_new` |
| **Versioning** | Per `AGENTS.md:184-185` **Never invent version** — use `@since NEXT` placeholder, replaced at release. Do not guess `2.0.0` / `3.8.0` | `NEXT` replaced by `scripts/bump-version` at tag time |
| **Removal timeline** | Warn for ≥1 minor, soft-remove (warning) before hard removal — migrates LiteSpeed/Redis operators safely | |

---

## 5. Consolidated Migration Compatibility Matrix (all axes at a glance)

| Change ID | WP 6.2 | WP 7.1 | PHP 8.2 | PHP 8.3-8.5 | Single | Multi `--url` | Multi `--network` | WP-CLI ✓ | WP-CLI ✗ | Cron | REST | Admin | Frontend | Obj cache ext | Obj cache none | Apache | Nginx | LiteSpeed | Risk |
|-----------|--------|--------|---------|-------------|--------|---------------|-------------------|----------|----------|------|------|-------|----------|---------------|---------------|--------|-------|-----------|------|
| C-WPCLI-01 global | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — | — | ✅ | ✅ | ✅ | Low |
| C-WPCLI-02 synopsis | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | n/a | n/a | n/a | n/a | n/a | — | — | — | — | — | Low |
| C-WPCLI-03 format | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | n/a | n/a | n/a | n/a | n/a | — | — | — | — | — | Low |
| C-WPCLI-04 progress | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | n/a | n/a | n/a | n/a | n/a | — | — | — | — | — | Low |
| C-WPCLI-05 dry-run/yes | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | n/a | n/a | n/a | n/a | n/a | — | — | — | — | — | Low |
| C-MULTI-06 `is_multisite` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | n/a | — | — | — | — | ✅ | ✅ | — | — | — | Low (guard) |
| C-MULTI-07 `switch_to_blog` | ✅ | ✅ | ✅ | ✅ | n/a | ✅ | ✅ | ✅ | n/a | n/a | n/a | n/a | n/a | ✅* | ✅ | — | — | — | **Med** (restore) |
| C-TRANS-08 `transient_key` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | Low |
| C-FS-09 `WP_Filesystem` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — | — | ✅ | ✅ | ✅* | Low |
| C-HOOK-10 fences | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — | — | ✅ | ✅ | ✅ | **Med** |
| C-HOOK-11 new `wppo_*` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | Low |
| C-ALIAS-12 deprec | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — | — | — | — | — | Low |

`*` Object cache: switch loop needs explicit `clear_settings_cache` per blog. `*` LiteSpeed FS: restart hint after `.htaccess`.

**Aggregate verdict:** All 12 changes are **backwards-compatible** when guarded as described. Only two carry medium risk and need `try/finally restore` + `try/catch is_multisite` + E2E `switch_to_blog` test.

---

## 6. Backward-Compat, `@since`, Deprecation & Alias Playbook

### 6.1 `@since` Policy (per `AGENTS.md:184-185`)

- New functions / methods / classes / filters / CLI flags → `@since NEXT`. Do not write `2.0.0`.
- New CLI positional/assoc args → doc `## OPTIONS` `--- default: + options:` + `@since NEXT` in PHPDoc.
- New `wppo_*` hooks → `docs/hooks.md` entry with `@since NEXT`.
- Example: `/** @since NEXT */ private function wppo_is_cli(): bool { return defined('WP_CLI') && WP_CLI; }`

### 6.2 Deprecation Pattern

```php
/**
 * @since 1.9.0
 * @deprecated NEXT Use `clear` instead. Alias retained for one minor.
 */
public function purge( array $args, array $assoc_args ): void {
    WP_CLI::warning( 'wppo cache purge is deprecated — use: wp wppo cache clear' );
    $this->cache( array( 'clear' ), $assoc_args );
}
```

- Warn for ≥1 minor, log via `WP_CLI::warning` (STDERR, exit 0 — handbook `warning` vs `error` at `WP-CLI-CURRENT.md:83`), then remove. Keep old filter `apply_filters('wppo_old',…)` as shim.

### 6.3 Alias Inventory (existing + proposed)

| Canonical | Alias(es) | Currently | Proposed |
|-----------|-----------|-----------|----------|
| `database --type=revisions` | — | ✅ | — |
| `database --type=auto_drafts` | `drafts` | ✅ `class-wppo-cli-command.php:242` | keep |
| `trashed_posts` | `trash` | ✅ `:246` | keep |
| `spam_comments` | `spam` | ✅ `:250` | keep |
| `trashed_comments` | `trashed` | ✅ `:254` | keep |
| `expired_transients` | `transients` | ✅ `:258` | keep |
| `orphan_postmeta` | `orphans` | ✅ `:262` | keep |
| `unattached_media` | `unattached` | ✅ `:266` | keep |
| `oembed_cache` | `oembed` | ✅ `:270` | keep |
| `cache clear` | `cache purge` | — | add `@since NEXT` alias |
| `database cleanup --type=all` | — | — | keep `--dry-run` + `--yes` new flags (not aliases) |
| `object-cache enable --host` | `--mode/--nodes/--master_name/--use_tls/--persistent/--compression` missing | gap `class-wppo-cli-command.php:864-871` vs `class-rest.php:1104-1117` | add full allowlist `@since NEXT` |

---

## 7. Multisite Operational Guidance (CLI Operator View)

| Need | Single-site command | Multisite single-site | Multisite network |
|------|---------------------|-----------------------|-------------------|
| Clear cache for one blog | `wp wppo cache clear` | `wp --url=https://sub.example.com wppo cache clear` | `wp wppo cache clear --network` (loop) |
| Clear one URL | `wp wppo cache clear --page=/sample-page/` | `wp --url=… wppo cache clear --page=/sample-page/` | `wp wppo cache clear --page=/sample-page/ --network` |
| DB cleanup all bloat | `wp wppo database cleanup --type=all` | `wp --url=… wppo database cleanup --type=all` | `wp wppo database cleanup --type=all --network` (+ `make_progress_bar` per blog) |
| Preview without mutating | add `--dry-run` | same | `wp wppo database cleanup --type=all --network --dry-run` |
| Settings export (machine) | `wp wppo settings export` | `wp --url=… wppo settings export --format=json` | iterate or `wp wppo settings export --network --file=/tmp/combined.json` (array keyed by blog_id) |
| Object cache flush | `wp wppo object-cache flush` | `wp --url=… wppo object-cache flush` | **Warn:** persistent cache flush is all-sites (`WP_Object_Cache::flush` note at `ECOSYSTEM-RESEARCH.md:52`) — CLI must `WP_CLI::warning('flush is all-sites; prefer --url for single')` |

**Uninstall reference sweep** (`uninstall.php:193-217`): canonical `get_sites number 100 offset` + `switch_to_blog` + `restore_current_blog` + `do-while`. CLI `--network` must clone that pagination verbatim, not `get_sites(['number'=>0])` unbounded.

---

## 8. Verification Recipe (Before Any Migration PR)

1. **WP matrix** — `wp core install` 6.2 → 7.1 per `readme.txt:4,6` plus nightly; `WP_CLI` 2.8+; run `wp wppo --help` (7 subcommands, `object-cache`/`system-info` via `@subcommand`), `wp wppo cache status --format=json | jq`, `wp wppo database counts --format=table`, `wp wppo settings get --format=yaml` with/without `--yes`.
2. **PHP matrix** — `parallel-lint` 8.2-8.5 (`psalm-wpcs-check.yml`), `composer test` (PHPUnit+Brain Monkey) — add `WPPO_CLI_MultisiteTest.php` & `WPPO_CLI_FS_Test.php` (`*Test.php` rule `AGENTS.md:162`), `ReflectionMethod` for private `switch_to_blog` helper.
3. **Multisite** — spin `wp core multisite-install` (subdomain + subdir), `wp site create --slug=blog2`, test `wp --url=blog2 wppo settings get` isol, `wp wppo database cleanup --type=transients --network --dry-run` prints per-blog preview without `DELETE`, `wp wppo cache clear --network --yes` calls `restore_current_blog` count === `switch_to_blog` count.
4. **Object cache** — enable `WP_REDIS` (predis) on multisite with shared Redis, verify `2_wppo_cache_size` vs bare `wppo_cache_size` via `wp transient get` after `wp --url=blog2 wppo cache status`; flip without ext cache and verify `_transient_` rows deleted correctly.
5. **WP_Filesystem** — mock `WP_Filesystem()->justReturn(false)` (as in `tests/php/bootstrap.php:261` + `MainBlockAssetsTest.php:33`) → CLI `settings export --file=/tmp/x.json` must `WP_CLI::error('Unable to initialize filesystem.')` exit 1, not fatal.
6. **Hosting** — Apache unwritable `.htaccess` → `settings update` warns; Nginx `.distignore` excluded vendor preserved; LiteSpeed with `is_litespeed()` true → `cache clear --network` does not skip `wppo_after_cache_clear` and logs restart hint; OpenLiteSpeed cold restart not required for CLI test.
7. **Perf** — XHProf `Main::__construct + setup_hooks + Cron::schedule_cron_jobs` before vs after fences (P0+P10+P11) on `wp wppo system-info` (expect 0.4-0.7 ms vs 1.2-2.0 ms per `PERF-RESEARCH.md:243-244`).
8. **Lint/build** — `npm run lint:js` → `composer lint` → `npm test` → `npm run build` per `AGENTS.md:29` required order; `composer release` lock check.

---

## 9. Risk-Ordered PR Split (Research-only — not enacted)

| PR | Changes | Files | Guard |
|----|---------|-------|-------|
| PR-A | C-WPCLI-02, C-WPCLI-03, C-ALIAS-12 (help + format) | `class-wppo-cli-command.php`, `docs/hooks.md` | `class_exists(Formatter)` fallback |
| PR-B | C-WPCLI-04, C-WPCLI-05 (progress + dry-run/yes) | `class-wppo-cli-command.php`, `class-database-cleanup.php` (preview counts helper) | `--yes` avoids CI hang |
| PR-C | C-MULTI-06, C-MULTI-07, C-TRANS-08 (multisite) | `class-wppo-cli-command.php`, `class-util.php` (doc) | `function_exists('is_multisite')` + `try/catch` + `finally restore` + paginated `get_sites` |
| PR-D | C-FS-09, C-HOOK-10, C-HOOK-11 (FS lazy + fences + new hooks) | `class-main.php:346-349,485-799`, `class-cache.php:1349`, `class-cron.php:57-74` | `wppo_enable_context_fences` filter rollback + keep `Util::ensure_settings_cache_hook` always |

---

## 10. Non-Goals / Already-Correct (No Migration Needed)

- `Util::get_settings():145-157` per-blog memo + `Util::cached_home_url():752-768` memo + `Util::transient_key():781-790` prefix — optimal, keep.
- Cache buffer gating `is_cache_allowed_for_current_user():297-301` + `is_not_cacheable():1482-1520` correctly skips writes on CLI/REST/Admin.
- `WP_CLI::add_command('wppo', Class):472-474` guard + `@when after_wp_load` (ignored when plugin-loaded) — correct.
- REST `manage_options + X-WP-Nonce` (`class-rest.php:357-361`) except `rum_collect` public token+rate-limit; CLI `manage_options` not needed (shell trust).
- Drop-in `advanced-cache.php` (`class-advanced-cache-handler.php:128`) zero-hook by design (pre-WP boot) — do not add hooks.
- `cron_schedules` `every_5_hours` (`class-cron.php:61`) always cheap — no fence needed.

---

*Evidence files read in full: `readme.txt`, `composer.json:12,55`, `performance-optimisation.php`, `includes/class-main.php` (170-340 defaults, 436-799 setup_hooks, 1032-1131 on_settings_update), `includes/class-util.php` (43-58 allowlist, 87 cached_home_url, 91-248 settings memo, 781 transient_key, 322 init_filesystem, 877 sanitize), `includes/class-cron.php` (57-74, 84-87 trigger_preload, 288 lock, 500 sitemap cap), `includes/class-activate.php:110-187`, `includes/class-deactivate.php`, `uninstall.php:106-130,146-217`. No production edits — this file is the sole write.*
