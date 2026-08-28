# ARCH-RESEARCH — Architecture for CLI & Hook Extensibility

**Date:** 2026-08-28 · **Plugin:** `performance-optimisation` v1.9.0 · **Scope:** `performance-optimisation.php:1-70`, `includes/class-main.php:1-3053` (Main 3053 lines), `includes/class-util.php:1-915` (Util 915), `includes/class-cache.php:1-2356` (Cache 2356), `includes/class-wppo-cli-command.php:1-973` (CLI 973), `includes/class-image-optimisation.php:3248`, `includes/class-rest.php:1620`, `includes/class-cron.php:751`, `AGENTS.md:18-19` (manual loading, no PSR-4) · **Method:** Full file reads + `wc -l` + `grep -rn "add_action|add_filter"` (272 hits, see `HOOK-AUDIT.md`), comparison to mature plugin patterns (WooCommerce, WP Rocket, LiteSpeed Cache). Research-only, no production edits.

> Companion: `WP-CLI-CURRENT.md` (CLI state), `HOOK-AUDIT.md` (272 hits), `HOOK-GAPS.md` (32 gaps), `PERF-RESEARCH.md` (context cost), `ECOSYSTEM-RESEARCH.md` (handbook norms). This doc answers: extend or refactor before adding CLI/hook features?

---

## 0. Verdict — Extend Incrementally, Refactor in Thin Slices (Not Big-Bang)

**Recommendation: DO NOT do a 3k-line Main rewrite before adding CLI/hook work. Extend the current architecture with small, reversible refactors gated behind `apply_filters('wppo_enable_context_fences', true)` (see `PERF-RESEARCH.md:272`).**

| Path | What | Pros | Cons | When to choose |
|------|------|------|------|----------------|
| **A. Extend only** (no refactor, just add `@subcommand` methods + `apply_filters` hooks at `HOOK-GAPS.md` gap points) | Add 5–10 new filters (`wppo_should_cache_request:G-01`, `wppo_cli_redis_config:G-22`, etc.) + 2–3 new CLI subcommand flags (`--dry-run`, `--yes`, `--format`) directly in existing classes | Zero risk to `Main:3053` lifecycle; ships in 1 PR; parity with `docs/hooks.md` fast; CLI delegation stays direct | Accumulates debt: `Main:3053` + `Util:915` + `Cache:2356` remain God objects; every new CLI flag duplicates validation logic already in `Rest:1620`; `setup_hooks:485-799` stays ~85 hooks always-loaded (see `PERF-RESEARCH.md:2.1`); testing stays hard (no service to mock) | If release pressure high (WP.org 1.9→1.10) and next 2–3 CLI features are small (`cache status --format`, `database cleanup --dry-run`) |
| **B. Incremental service extraction** (recommended) | Extract **3–4 shared services** + **context-aware bootstrap** + **PSR-4 autoload (C01)** in 3 PRs (see §8 split), then add new CLI/hooks on top of services | Fixes root causes: testability (Brain Monkey can mock service), CLI can delegate to service (not copy `Rest` logic), `setup_hooks:485-799` drops 60 % waste on CLI (see `PERF-RESEARCH.md:6`), autoload removes 11 `file_exists`/`require_once` at `Main:438-474` | Requires 3 PRs + `composer dump-autoload` + release pipeline test (`scripts/build-release.sh` uses `--optimize-autoloader`); risk: manual-load → PSR-4 touches `composer.json` `autoload.classmap` → `psr-4` migration must keep `vendor/autoload.php` path at `performance-optimisation.php:41` | If 5+ new CLI subcommands or 8+ new `wppo_*` hooks planned (see `HOOK-GAPS.md:25-58` — 32 gaps), or PHP tests must cover CLI |
| **C. Big-bang refactor** (container, DI, event bus, hook registry) | New `Container`, `Hook_Registry`, `Context` classes; split `Main` into `Bootstrap`, `Frontend_Manager`, `Admin_Manager`, `Cron_Manager`; move all `add_action` into registry | Architecturally pure; matches WooCommerce `Container` / WP Rocket `ServiceProvider` | **Architecture for architecture's sake** — violates AGENTS.md “no routing/state lib” minimalism; 3k-line rewrite invalidates 3 years of `TODO #553` version gates (`is_wp63_plus`, `is_wp69_plus` at `Main:501-510`), LiteSpeed coexistence (`should_bypass_for_litespeed:398-406`), and `Util::cached_home_url` memo (`Util:752-768`); high regression; blocks WP.org release | Never — only if plugin rewrites to Symfony-style container for SaaS |

**Why B wins:** `HOOK-GAPS.md` already identifies 32 gaps, 3×P0 (`G-01` cache veto, `G-15` per-type DB action, `G-28` optimise gate). Adding those as one-off `apply_filters` at `Cache:1505`, `Database_Cleanup:935`, `Main:369` works without refactor. But `WPPO_CLI_Command:973` already duplicates `Rest:464` sanitization (`Util::sanitize_settings_recursively` at `CLI:726` vs `Rest:476`) and `Object_Cache` allowlists (CLI 6 keys at `CLI:864-871` vs REST 10 at `Rest:1105`). Extending without services means the **next** CLI feature copies the same divergence. A thin service layer pays back in 2 features.

---

## 1. Class Responsibilities — Current Size & Coupling

| Class | Lines (`wc -l`) | Primary responsibility (from file header + method scan) | Coupling / debt signal | File |
|-------|----------------|----------------------------------------------------------|------------------------|------|
| **Main** | **3053** | God object: bootstrap (`__construct:169-357`), hook wiring (`setup_hooks:485-799`), file-optimisation list parsing (`delayJSDefaultStrategy:72-104`), role-hash cookie (`400-426`), version migration (`873-905`, `997-1023`), `on_settings_update:1032-1131` (cache clear + drop-in + htaccess + Google Fonts), output-buffer orchestration (`1243-1352`), admin localize (`1495-1618`), defer/delay/minify/combine/preload/speculation wiring | **Too large.** Owns 5 concerns that mature plugins split: (1) bootstrap/config, (2) frontend optimisation, (3) admin SPA, (4) cache lifecycle, (5) version migration. `exclude_css:38`, `exclude_js:46`, `exclude_defer_js:56`, `exclude_delay_js:64` + `delay_js_*` 5 properties are file-optimisation state that belongs in a `File_Optimisation_Service`. `setup_hooks:485-799` is 314 lines, ~46 unconditional `add_action/filter` + version-gated branches (`is_wp63_plus:501`, `is_wp69_plus:510`) with `TODO #553` legacy fallbacks — hard to test without booting WP | `class-main.php` |
| **Image_Optimisation** | 3248 | Next-gen serving, lazy-load, placeholder, video lazy, LCP prioritize | Largest file; mixes buffer regex + `WP_HTML_Tag_Processor` + `Util::cached_home_url` memo; `__construct` registers 6 filters at `185-213` always | `class-image-optimisation.php` |
| **Cache** | 2356 | Static HTML cache (`CACHE_DIR:/cache/wppo:40`), combine CSS (`408-562`), CDN rewrite (`1331-1414`), buffer pipeline (`1243-1316`), inline-budget simulation (`750-1110`), `is_not_cacheable:1482` | Second God object. `combine_css:414` + `core_will_inline:750` + `should_skip_combine:1126` are 3 coupled decisions that should be a `Combined_CSS_Service`. `maybe_apply_cdn:1331` does 5-way CDN gate (LiteSpeed + `wppo_litespeed_can_cdn` + `litespeed_can_cdn` + empty `cdnURL` + `WP_HTML_Tag_Processor` missing) — extraction would make CLI `cache clear` testable without `Cache` | `class-cache.php` |
| **Util** | 915 | Static helpers: `ALLOWED_SETTINGS_KEYS:43`, `get_settings:145` (per-blog memo `91-106`), `cached_home_url:752`, `transient_key:781`, `sanitize_settings_recursively:877`, FS `prepare_cache_dir:288`, URL `process_urls:526`, `is_url_excluded:548` | **Best-shaped class** — already stateless + per-blog memo (`91-106`, `87`) with `switch_blog:214` isolation. Should remain pure utility; not a service. `sanitize_settings_recursively:877` is the shared sanitizer for REST (`Rest:531`) + CLI (`CLI:654,726`) — correctly extracted | `class-util.php` |
| **Img_Converter** | 1865 | WebP/AVIF convert (`convert_image:319`), deferred option commits (`commit_img_info:1750` on `shutdown`), library discovery (`queue_unconverted_library_images`) | Holds conversion policy (`filesize 20M:G-11`, dims `5000×5000`) that should be filters (`wppo_filesize_limit_bytes:402`, `wppo_max_image_dimensions:422` exist but undocumented — `HOOK-AUDIT.md:15` lists 5 undoc converts) | `class-img-converter.php` |
| **Rest** | 1620 | 25 routes (`get_routes:58-260`), `update_settings:464`, `database_cleanup:819`, `object_cache:1025` | Duplicates CLI validation (allowlists at `Rest:470` vs `CLI:644,728`); `sanitize_settings_recursively:531` delegates to `Util` — good; `build_redis_config:1104` allowlists 10 keys vs CLI 6 — divergence (see §3) | `class-rest.php` |
| **Cron** | 751 | Scheduling (`schedule_cron_jobs:114`), batch preload (`283-351` with `200` batch, `0-1800` jitter, `500` sitemap cap), image discovery (`635-700` with `wppo_cron_discovery_limit:666`) | Hard caps (`200`, `500`, `15s`, `50` at `Cron:301,364,496,49`) with single filter (`wppo_cron_discovery_limit:666`) — `HOOK-GAPS.md:G-05` proposes 4 more | `class-cron.php` |
| **WPPO_CLI_Command** | 973 | 7 `@subcommand` methods (`cache:75`, `database:174`, `image:321`, `settings:573`, `object_cache:801`, `pagespeed:902`, `system_info:956`) + helpers `get_default_settings:451` + `get_redis_config_from_assoc:864` | **Boundary violation** — `image:321` does `realpath + ABSPATH` prefix at `353-355` + `new Img_Converter` + `convert_image` loop inline (service work inside command). Should delegate to `Image_Service::convert_batch()` | `class-wppo-cli-command.php` |
| **System_Info** | 633 | `get_all` groups (`php,database,wordpress,wp_constants,server,cache,infrastructure`) | Fine — CLI `system_info:956` delegates correctly to `System_Info::get_all()` (see §3) | `class-system-info.php` |
| **Object_Cache** | 363 | Redis drop-in (`enable:252`, `disable:325`, `ping:205`, `get_status:86`) + `wppo-redis-config.php` generation | Small, focused; CLI divergence (6 vs 10 keys) is the only gap | `class-object-cache.php` |

**Diagnosis:** Two God objects (`Main:3053`, `Cache:2356` + `Image_Optimisation:3248`) own ~8k lines (65 % of `includes/`). `Util:915` is the only clean shared kernel. Everything else delegates through `Util` or `Main` — no service layer, no interface, no container.

---

## 2. DI Opportunities & Shared Services (What to Extract)

> “Avoid architecture for architecture’s sake.” — Extract only where CLI/hook work currently duplicates logic or blocks tests.

| Service (new or promoted) | Extracted from | Methods to move / share | CLI benefit | Hook benefit | Cost |
|---|---|---|---|---|---|
| **Settings_Service** | `Main:170-340` defaults merge + `Util:145-157` memo + `Main:1032-1131` `on_settings_update` | `get_settings():Util:145`, `get_default_settings():Main:240` + `CLI:451` (duplicate), `sanitize_settings_recursively():Util:877`, `validate_tab():Rest:470` / `CLI:644,728`, `on_settings_update` invalidation routing | CLI `settings {get,update,export,import}:573` + `Rest:update_settings:464` share one `validate + sanitize + merge + update_option` path; eliminates `CLI:644` vs `Rest:470` allowlist drift; `get_default_settings` single source (currently omits 7 tabs `litespeed_integration,llms_txt,od_integration,bfcache,perf_translations,ai_adaptive,edge_cache` at `Main:240` vs `CLI:451`) | Settings filters `wppo_settings_sanitize / before_update / after_update:G-20` land in one place | ~80 lines extracted; `Main:170-340` + `Util:145` stay, but defaults single-sourced |
| **Cache_Service** | `Cache:2306` + `Main:552,787-791` invalidation + `Cron:283-351` preload | `clear_cache($path?)`, `get_cache_stats()`, `invalidate_dynamic_static_html()`, `schedule_preload()` (wraps `Cron:283`) | `wp wppo cache {clear,preload,status}:75` delegates to `Cache_Service` methods already called at `CLI:86,103,117` — remove inline `wp_parse_url` path logic at `CLI:102` in favour of service validation (`realpath` at `Rest:413` vs CLI normalize — `PERF-RESEARCH.md:2.2` gap R03) | `wppo_should_cache_request:G-01`, `wppo_cache_written:G-02`, `wppo_invalidation_urls:G-03` become service filters, not scattered `Cache:1505,1741,1838` patches | ~60 lines; `Cache` stays for buffer, service wraps invalidation |
| **Database_Cleanup_Service** | `Database_Cleanup:1113` + `Rest:819` + `CLI:174` | `clean_all()`, `clean_revisions()` etc., `get_counts()`, `optimize_table()`, `get_valid_cleanup_types()` | CLI `database {cleanup,optimize,counts}:174` already delegates correctly — but `TABLE_MAP:42` + `CLEANUP_METHOD_MAP:81` allowlists duplicated at `CLI:180` (`array_merge(...TABLE_MAP)`) should be `Service::allowed_tables()` | `wppo_before_database_cleanup:G-15`, `wppo_db_batch_size:G-16` become service filters; fixes P0 gap where per-type cleaners fire no action (`HOOK-AUDIT.md:6` — only `clean_all` fires `wppo_database_cleanup_completed:737`) | Minimal — mostly expose constants via service accessor |
| **Image_Service** | `Img_Converter:1865` + `Image_Optimisation:3248` | `convert_image($src,$fmt)`, `get_img_info()`, `queue_unconverted_library_images()`, `convert_batch($pending,$batch)` | **Highest ROI** — CLI `image convert:325-367` currently does `realpath` loop + `counter>=batch` break + `Log::add` inline; should be `Image_Service::convert_batch($format,$batch)` returning `{converted,total_pending}`; enables `WP_CLI\Utils\make_progress_bar` (see `ECOSYSTEM-RESEARCH.md:1.2`) | `wppo_should_convert_image:G-11`, `wppo_conversion_quality:G-11` become service filters at `Img_Converter:319,377` | ~40 lines extracted; `Img_Converter` stays for low-level encode |
| **Object_Cache_Service** | `Object_Cache:363` (already service-like) | `get_status()`, `ping($cfg)`, `enable($cfg)`, `disable()`, `flush()` | Fix 6-vs-10 key divergence: `CLI:864` allowlists `host,port,password,database,timeout,prefix` only; `Rest:1105` allowlists `mode,nodes,master_name,use_tls,persistent,compression` too. Service exposes `ALLOWED_KEYS` constant, both CLI + REST use it. Add `wppo_cli_redis_config:G-22` + `wppo_object_cache_config:G-19` | `wppo_object_cache_before_enable:G-19` lands in service | ~20 lines — promote allowlist to service constant |

**What NOT to extract yet:** `Cron` batch planner (`200` posts/batch, `500` sitemap cap, `15s` deadline), `Telemetry`/`Pagespeed` API, `Litespeed_Integration` mode — leave until a CLI subcommand needs them (e.g. `wp wppo cron status`, `wp wppo litespeed mode`).

**DI style:** Keep WordPress-idiomatic — **no Symfony Container**. Use constructor injection of `$options` array (already at `Main:343-344 new Image_Optimisation($options)`) + static service accessor `WPPO::settings()` or simple `Service_Locator` returning singletons lazy-loaded via `class_exists($fqcn, true)` (PSR-4). Mature pattern: WP Rocket uses `ServiceProvider` + `Container` but we can mimic WooCommerce’s lightweight `Container` (`wc_get_container()->get(Class::class)`) without pulling `league/container`.

---

## 3. CLI Service Boundaries — `WPPO_CLI_Command:973` Should Delegate

Current state (from `WP-CLI-CURRENT.md:3` + file reads):

- `cache:75` correctly delegates to `Cache::get_cache_stats:86`, `Cache::clear_cache:103,117`, `Cron::trigger_preload:79` — **good boundary** (thin command, service does I/O).
- `database:174` delegates to `Database_Cleanup::{clean_all:215, clean_revisions:239, optimize_table:187, get_counts:201}` — **good**, but re-derives `allowed_tables` at `180` instead of asking service.
- `image:321` **violates** — does path safety (`realpath:353`), batch loop (`343-360`), `Log::add:363` inside command. Should be `Image_Service::convert_pending($format,$batch)` + `Image_Service::status()` (see `WP-CLI-CURRENT.md:3.3` gap: sync-only, no async scheduler, no `--batch-size` flag).
- `settings:573` **violates** — `get_default_settings:451` duplicates `Main:170` defaults (missing 7 tabs); `export:590` does `WP_Filesystem->put_contents` inline; `import:622` does allowlist + `sanitize_settings_recursively:654` + `update_option` inline — should be `Settings_Service::export($file)`, `import($file)`, `update($tab,$json)`, `get($tab,$format)`.
- `object_cache:801` delegates to `Object_Cache:803` but through **narrow** `get_redis_config_from_assoc:864` (6 keys) — should use shared `Object_Cache_Service::ALLOWED_KEYS:10`.
- `pagespeed:902` delegates to `Pagespeed::{queue_scan:908, get_results:921}` — **good**.
- `system_info:956` delegates to `System_Info::get_all:957` — **good**, but lacks `--format` (see `ECOSYSTEM-RESEARCH.md:1.2` gap).

**Target boundary (handbook `WP_CLI::add_command` cookbook):**

```php
// wp wppo image convert --format=webp --batch-size=50 --dry-run --yes
public function image( $args, $assoc_args ): void {
    $format = $assoc_args['format'] ?? $this->settings->conversion_format(); // service, not raw Util::get_settings
    if ( $this->confirm_if_needed( 'Convert %d images?', $assoc_args ) ) { // WP_CLI::confirm + --yes
        $result = $this->images->convert_batch( $format, $this->batch_size($assoc_args), $this->is_dry_run($assoc_args) );
        $this->formatter->display( $result, $assoc_args ); // WP_CLI\Formatter table|json|csv
    }
}
```

- Command: parse `$args/$assoc_args`, call `WP_CLI::confirm`/`WP_CLI\Utils\get_flag_value`, delegate to service, format via `WP_CLI\Formatter`/`format_items`.
- Service: `realpath` + `ABSPATH` prefix, batch loop, `convert_image`, `Log::add`, return DTO.
- Enables `WP_CLI\Utils\make_progress_bar` for `image convert 100` + `database cleanup --type=all 9×∞` without command knowing `Img_Converter` internals.
- Tests: `tests/php/WPPO_CLI_CommandTest.php` can mock `Image_Service` via Brain Monkey `when('WP_CLI::success')->justReturn()` (see `AGENTS.md` testing quirks — no CLI tests exist today).

---

## 4. Hook Registration — `setup_hooks:485-799` (314 Lines, ~85 Hooks Always-Loaded)

From `HOOK-AUDIT.md:0` (272 total) + `PERF-RESEARCH.md:2.1` (85 per-request):

- **Always-loaded 46** at `setup_hooks:485-799` + **12–18 conditional** (`enableCache:539`, `delayJS:514`, `deferJS:522`, `minify*:655`, `combineCSS:599`, `criticalCSS:768`) + **22–30 via helpers** (`new Cron:57-74` 12 hooks, `new Metabox:39-41` 2, `new Asset_Manager:79,82` 2, `new Abilities:35-36` 2 at `Main:762-765`, `Image_Optimisation:185-213` 6 at `Main:343`).
- **“270 hooks always-loaded?”** — No. `PERF-RESEARCH.md:2.3` corrects prompt: 272 hits include 78 `apply_filters` + 22 `do_action` **call sites**, not registrations. Real per-request registrations = **70–105**, still over-including (CLI registers ~60 irrelevant hooks, see `PERF-RESEARCH.md:2.4`).

**Architecture options for hook registration:**

| Option | Description | Pros | Cons |
|--------|-------------|------|------|
| **A. Keep `setup_hooks:485-799` monolith, gate internals** | Wrap blocks in `if (!is_admin() && !WP_CLI && !DOING_CRON && !REST_REQUEST)` etc. (see `PERF-RESEARCH.md:5.2 P10` — 0.3–0.6 ms saved on CLI) | Single file to audit; version gates `is_wp63_plus:501`, `is_wp69_plus:510` stay together; `TODO #553` legacy fallbacks co-located | Still 314 lines; helper instantiations (`new Cron`, `new Metabox`) remain eager at `Main:762-765`; no registry, no discoverability |
| **B. Extract `Hook_Registry` + `Context`** (recommended, incremental) | Create `Context::is_frontend(): !is_admin && !WP_CLI && !DOING_CRON && !REST_REQUEST` (see `PERF-RESEARCH.md:7` risk table) + `Frontend_Hooks`, `Admin_Hooks`, `Cron_Hooks` registrars that `setup_hooks` delegates to: `if (Context::is_frontend()) Frontend_Hooks::register($this->options, $this->cache, $this->image_optimisation)` | Matches WP Rocket `ServiceProvider` per-context split; makes `PERF-RESEARCH.md:4` context matrix explicit; enables `add_action('init', [Cron::class,'schedule_cron_jobs'])` gating (P11 saves 0.3–1.0 ms on front/CLI) | Splits `setup_hooks` across 3 files — must keep `LiteSpeed_Integration::init:797` always-registered outside matrix |
| **C. Event bus / `do_action('wppo_register_hooks')`** | Fire `do_action('wppo_register_hooks', $context)` and let `Cache`, `Image_Optimisation` self-register | Fully decoupled; plugins can add custom `wppo_after_cache_clear` consumers without touching `Main` | Over-engineered for 25-file plugin; `HOOK-AUDIT.md:5` already shows `wppo_after_cache_clear` has 2 consumers (`CDN_Purger:623`, `Edge_Purger:626`) — no need for bus |

**Recommendation:** **A now, B in PR-B.** For next CLI/hook feature, add `Context` helper (10 lines) and gate `P01-P05` (admin/metabox/abilities/duplicate autoload/eager FS) as `PERF-RESEARCH.md:5.1` P0 — zero risk, saves 0.4 ms on CLI. Defer full `Frontend_Hooks` split until a large feature (e.g. new `wppo_should_cache_request:G-01` that needs frontend-only filter) forces it.

**File:line map for context gates (from `PERF-RESEARCH.md:2.2`):**

- Admin-only: `admin_menu:486`, `admin_init×4:487-493`, `admin_enqueue_scripts:494`, `Metabox:762`, `Abilities:765`, `Admin_Notices:351` — gate `is_admin()`.
- Frontend-only: `init set_role_hash_cookie:495`, `wp_enqueue_scripts enqueue_scripts:497` + `apply_module_loading_strategies:498`, `wp_head preload:758` + `speculation_rules:759`, `wp_resource_hints:760`, `script_loader_tag/style_loader_tag` family `515,525,530,662,679,688`, `wp_footer RUM:619-620`, `combine_css:608`, `Server-Timing:560-561`, `LCP/used-CSS buffers:568-590` — gate `Context::is_frontend()`.
- Cron-only: `new Cron:763` lazy-gate `should_schedule_cron_jobs()` (see `PERF-RESEARCH.md:5.2 P11`).
- Always: `REST:615`, `wppo_after_cache_clear:623,626`, `save_post invalidate:552`, `LiteSpeed_Integration::init:797`.

---

## 5. Context-Aware Bootstrapping — CLI vs Admin vs Frontend

Current bootstrap (`performance-optimisation.php:41-44` → `Main::__construct:169-357` → `includes:436-475` → `setup_hooks:485-799`) is **context-unaware** — see `PERF-RESEARCH.md:1` “runs unconditionally”. Cost: CLI `wp wppo system-info` (read-only, needs only `System_Info::get_all`) still boots `Image_Optimisation:343` (6 filters), `Google_Fonts:344`, `Util::init_filesystem:346` (0.3–0.8 ms), `Core_Tweaks:356` (0–15 hooks), `new Cron:763` (12 hooks + `wp_next_scheduled×8` at `Cron:114`), and frontend `wp_head` chain — **~0.7–1.6 ms bootstrap** (`PERF-RESEARCH.md:3.1`) where ~60 % irrelevant (see `PERF-RESEARCH.md:2.4`).

**Mature plugin patterns:**

| Plugin | Bootstrap | Context fence | Why it applies |
|--------|-----------|---------------|----------------|
| **WooCommerce** | `woocommerce.php` → `WC_Install`, `WC_Frontend_Scripts`, `WC_Admin` split; `WC_Container` lazy services via `wc_get_container()->get(Class::class)` | `is_admin()` gates `WC_Admin`, `!is_admin() && !wp_doing_ajax()` gates `WC_Frontend_Scripts`; `WP_CLI` not loaded unless `WP_CLI` defined | Validates per-context `if (is_admin()) new Admin()` gate — same as `PERF-RESEARCH.md:P01-P04` |
| **WP Rocket** | `wp-rocket.php` → `ServiceProvider` registers `init`/`wp_loaded` hooks per service; `Container` resolves `Buffer_Subscriber`, `Cache_Subscriber` only when `DONOTCACHEPAGE` false | `Subscriber` pattern: each feature is a `SubscriberInterface` with `get_subscribed_events(): ['wp_head'=>['add_preload',1]]`; container instantiates only when event fires | Shows alternative to `setup_hooks` monolith — but adds indirection; our 25-file plugin does not need full `league/event` |
| **LiteSpeed Cache** | `litespeed-cache/litespeed-cache.php` → `LSCWP_CTRL` + `LiteSpeed_Cache_Control::init()` + CLI `cli/class-litespeed-cache-cli-purge.php` lazy-loaded only when `WP_CLI` | `if (defined('WP_CLI') && WP_CLI) require_once 'cli/...'` outside `setup_hooks` (like ours at `Main:472`) | Validates `WP_CLI` guard before `add_command` — ours correct (`Main:472-474`) |
| **Handbook** | `make.wordpress.org/cli/handbook/guides/commands-cookbook/#include-in-a-plugin-or-theme` → `if (defined('WP_CLI') && WP_CLI) WP_CLI::add_command(...)` after `vendor/autoload.php` + before hooks | `@when after_wp_load` ignored for plugin-loaded commands (WP already loaded) | Validates `WPPO_CLI_Command` `@when after_wp_load:69` is correct but redundant for plugin — keep for doc |

**Recommended bootstrap (incremental, no container):**

```php
// performance-optimisation.php:41-44 (keep)
require_once WPPO_PLUGIN_PATH . 'vendor/autoload.php';
new Main(); // Main::__construct remains, but:

// Main::__construct:169-357 — keep settings merge (negligible 0.02 ms), but:
$this->includes(); // keep, but remove duplicate vendor/autoload at Main:437, gate file_exists list via PSR-4 (C01)
$this->options = $this->load_options_with_backfills(); // extract method

// Main:setup_hooks:485-799 — add context fences (P01,P10):
if ( is_admin() ) { $this->register_admin_hooks(); } // admin_menu, admin_init×4, admin_enqueue_scripts, Metabox, Abilities
if ( Context::is_frontend() ) { $this->register_frontend_hooks(); } // 30 hooks: cache buffer, combine, minify, defer, RUM, preload, etc.
if ( Context::should_schedule_cron() ) { new Cron(); } else { // P11 — keep worker add_action but skip wp_next_scheduled
    add_action( 'wppo_generate_static_page', [Cron::class,'process_page'] ); // keep for AS
}
add_action( 'rest_api_init', [Rest::class,'register_routes'] ); // keep always (cheap, 1 add_action)
// Always: LiteSpeed_Integration::init:797, wppo_after_cache_clear:623,626, save_post:552
if ( defined('WP_CLI') && WP_CLI ) { \WP_CLI::add_command('wppo', WPPO_CLI_Command::class); } // keep at includes:472
```

- Keep `Util::get_settings:145` memo + `Util::cached_home_url:752` memo always (see `PERF-RESEARCH.md:7` risk — `switch_blog:248` must stay).
- Keep `Util::init_filesystem:346` **lazy** (P07) — move to `get_filesystem()` accessor already at `Cache:347-353` lazy pattern; `Main` eager `$filesystem` property never used after init.
- Expected saving: **0.4–0.7 ms on CLI** (`PERF-RESEARCH.md:6` 1.2→0.4 ms), **0.3–1.0 ms on front** when `Cron` scheduler gated.

---

## 6. Public API — Internal vs Public Hooks, Naming `wppo_*`

Current: `docs/hooks.md:1-439` documents **42 public `wppo_*`**; `HOOK-AUDIT.md:0` finds **78 `apply_filters` + 22 `do_action` fire sites** — drift ~15 undocumented (`HOOK-AUDIT.md:15` lists `wppo_filesize_limit_bytes:Img_Converter:402`, `wppo_cron_discovery_limit:Cron:666`, `wppo_server_timing_enabled:Main:1252`, `wppo_video_*`, `wppo_skip_combine_on_small_block_theme:Cache:1143`, etc.). All use `wppo_*` prefix — **good** (no collision with `litespeed_*`/`wp_*`).

**Classification (from `HOOK-AUDIT.md:26`):**

- `public` = intended for operators/themes (`wppo_before_cache_clear:Cache:2032`, `wppo_after_cache_clear:2074`, `wppo_exclude_delay_js:Main:722`, `wppo_cache_page_html:1661`, `wppo_database_cleanup_completed:Database_Cleanup:737`, `wppo_litespeed_*` 12, `wppo_edge_cache_*:Edge_Cache`, etc.) → **document in `docs/hooks.md`**.
- `private` = internal plumbing (`wppo_debug_log:Cache:282`, `wppo_run_upgrades:Main:489`, `wppo_web_vitals_rescan:Cron:68`, `wppo_convert_image_background:Main:775`) — not `wppo_*`? Actually `wppo_*` but internal cron names — keep `private` but prefix `wppo_` is fine; document as “internal, do not hook” or rename to `_wppo_*`? Handbook says internal hooks should still use plugin prefix but be documented as private.
- `external` = consumed, not owned (`litespeed_can_optm:Cache:402`, `litespeed_can_cdn:1353`, `styles_inline_size_limit:Cache:1047`) — keep `has_filter` + `apply_filters` read, never `do_action`.

**Naming rules (from `ECOSYSTEM-RESEARCH.md:1.2` + handbook):**

- Keep `wppo_*` for all plugin-owned filters/actions (already consistent). Good: `wppo_cache_page_html` vs `wppo_cache_page_HTML` — lowercase snake correct.
- New hooks from `HOOK-GAPS.md:25-58` follow `wppo_<noun>_<verb>` (`wppo_should_cache_request:G-01`, `wppo_cache_written:G-02`, `wppo_should_convert_image:G-11`). Good.
- Reserve `wppo_inline_combined_css:Cache:980` (mentioned in `AGENTS.md:79`) — keep; new `wppo_combine_css_should_combine:G-06` is distinct (per-handle vs global inline gate).
- CLI hooks: `wppo_cli_*` (`wppo_cli_redis_config:G-22`, `wppo_cli_should_run:G-22`, `wppo_cli_after_command:G-22`) — prefix `wppo_cli_` correct, distinct from `wppo_rest_*` etc.

**Internal vs public gate:** For public API, add `@since NEXT` to every new `apply_filters`/`do_action` (see `AGENTS.md:79` “Never invent version — use NEXT”). For internal, add `// Internal — not part of public API` comment and omit from `docs/hooks.md`.

**Gap to close before adding CLI/hook features:** Document 15 undoc `wppo_*` at `HOOK-AUDIT.md:15` in `docs/hooks.md` (one PR, no code) so new gaps `G-01—G-32` do not also drift undocumented.

---

## 7. Future Extensibility — What the Next 5 Features Need

| Future feature (from `WP-CLI-CURRENT.md:5` gaps + `HOOK-GAPS.md`) | Arch it touches | Extend or refactor? |
|---|---|---|
| `wp wppo cache clear --dry-run --yes --format=json` + `wp wppo database cleanup --dry-run` + `wp wppo settings import --dry-run` (handbook `search-replace --dry-run`) | CLI flags + service dry-run predicate | **Extend** — add `WP_CLI::confirm:CLI` + `get_flag_value --dry-run/--yes` in `WPPO_CLI_Command`, add `Service::should_run()` filter `G-22` |
| `wp wppo cache status --format=table|json|csv` + `database counts --format`, `image status --format`, `object-cache status --format`, `system-info --format --fields` (`format_items:G-07`) | `WP_CLI\Formatter` + `format_items` | **Refactor thin slice** — extract `Formatter` usage to `WPPO_CLI_Command` base helper (10 lines) so each `display()` uses `Formatter` not `wp_json_encode(PRETTY)` at `CLI:202,385,808,965` |
| `wppo_should_cache_request:G-01` (per-URL veto) + `wppo_should_optimise_for_user:G-28` (per-user gate) | `Cache:1505 is_not_cacheable` + `Main:369 should_optimise_for_logged_in` | **Extend** — single `apply_filters` at `Cache:1505` + `Main:369` (P0 gaps) — no service needed |
| `wppo_before_database_cleanup:G-15` + `wppo_database_cleanup_type_completed:G-15` (per-type) + missing `wppo_after_cache_clear` already exists | `Database_Cleanup:714 clean_all` + `935 invoke_cleanup_method` + `Rest:880` | **Extend** — add `do_action` at those 3 points — fixes silent P0 where per-type cleaners fire no action |
| Multisite `wp --url=<site> wppo cache clear` + `wp wppo cache clear --network` (switch_to_blog loop, Rocket/LSCWP pattern `ECOSYSTEM-RESEARCH.md:1.2`) | `WP_CLI` global `--url` + `get_sites()` + `switch_to_blog`/`restore_current_blog` | **Refactor slice** — add `Multisite_Command_Trait` with `foreach_site()` helper; `Util::transient_key:781` already blog-prefixes (`PERF-RESEARCH.md:2.4` multisite-safe), but CLI never calls it |

**Extensibility cost of current arch:** Adding a new `wppo_*` filter at `Cache:1505` is 1 line + `docs/hooks.md` entry. Adding a new CLI subcommand (`wp wppo rum flush`) is ~80 lines in `WPPO_CLI_Command` plus `Rest` parity. Both are **extension-friendly** today. What is *not* extension-friendly: **testing** (no service to mock) and **context cost** (every new `add_action` in `setup_hooks` adds to 70–105 per-request even when irrelevant).

---

## 8. Recommended Refactoring vs Extension — PR Split (Research-Only)

From `PERF-RESEARCH.md:9` (4 PRs) + `HOOK-GAPS.md:25-58` (32 gaps) + `ECOSYSTEM-RESEARCH.md:1.4` (11 CLI fixes):

| PR | Scope | Files | Risk | Why before CLI/hook work |
|----|-------|-------|------|--------------------------|
| **PR-Z (docs, 0 code)** | Document 15 undoc `wppo_*` + add `docs/hooks.md` missing entries (`HOOK-AUDIT.md:15`) | `docs/hooks.md` | None | So new gaps `G-01—G-32` start documented |
| **PR-A (P0 fences, <40 lines)** | `Context` helper + gate `P01-P07`: `is_admin` for `admin_menu:486` etc., `Metabox:762`, `Asset_Manager:764`, `Abilities:765`, duplicate `vendor/autoload:437` removal, lazy `init_filesystem:346` | `performance-optimisation.php`, `class-main.php:351,438,762-765`, `class-util.php:322` doc, new `class-context.php` (10 lines) | Low | Cuts CLI bootstrap 60 % (`PERF-RESEARCH.md:6`) so future `WP_CLI\Utils\make_progress_bar` not drowned in setup cost |
| **PR-B (P10+P12, ~60 lines)** | Wrap frontend block (`Main:485-799` 30 hooks) in `Context::is_frontend()` + lazy `Image_Optimisation:343`/`Google_Fonts:344` | `class-main.php:342-344,485-799` (reindent) | Medium | Makes `setup_hooks:314` readable; future `wppo_should_cache_request:G-01` clearly frontend-only |
| **PR-C (P11, ~30 lines)** | Gate `Cron::schedule_cron_jobs:114` `wp_next_scheduled×8` behind `Context::should_schedule_cron()` / 1h transient lock | `class-cron.php:57-129`, `class-main.php:763` | Low | Saves 0.3–1.0 ms on every front/CLI (`PERF-RESEARCH.md:6`) |
| **PR-D (C01, PSR-4, later)** | `composer.json` `autoload: classmap → psr-4 PerformanceOptimise\Inc\ → includes/` + remove manual `require_once` list `Main:438-474` + `composer dump-autoload` | `composer.json`, `composer.lock`, `class-main.php:438-474`, `performance-optimisation.php:41` | Medium — release pipeline | Cleaner architecture per `AGENTS.md:18-19` manual-load warning, but defer until WP.org 2.0 |
| **PR-E (CLI services, ~120 lines)** | Extract `Settings_Service` (single `get_default_settings`), `Image_Service::convert_batch`, `Object_Cache_Service::ALLOWED_KEYS`, add `WP_CLI\Formatter` helper + `--dry-run/--yes/--format` flags | `class-wppo-cli-command.php:451,864,75,174,321,573,801`, new `class-settings-service.php` etc. | Medium | Fixes `CLI:644` vs `Rest:470` drift, 6-vs-10 key gap, enables tests |
| **PR-F (hook gaps P0+P1, ~80 lines)** | Add `G-01, G-15, G-22, G-19, G-20` (`wppo_should_cache_request`, `wppo_before/after_database_cleanup`, `wppo_cli_redis_config`, `wppo_object_cache_*`, `wppo_settings_*`) | `class-cache.php:1505`, `class-database-cleanup.php:714,935`, `class-wppo-cli-command.php:864`, `class-object-cache.php:252` | Low — additive filters | Unlocks agency extensibility without fork |

**Order:** `Z → A → B → C → E → F → D`. `A`+`B`+`C` are context fences (no new public API); `E`+`F` are CLI/hook features built on fenced bootstrap; `D` is optional tech-debt payoff.

---

## 9. Mature Plugin Pattern Comparison — What to Borrow, What to Skip

| Pattern | WooCommerce | WP Rocket | LiteSpeed Cache | Borrow for WPPO? |
|---------|-------------|-----------|-----------------|------------------|
| **PSR-4 autoload** | `composer.json psr-4: Automattic\WooCommerce\ → src/` | `psr-4: WP_Rocket\ → inc/` | Manual `require_once` like us | **Yes, PR-D later** — Woo/Rocket both PSR-4; WPPO `AGENTS.md:18` flags manual load as debt (`C01`) |
| **Container** | `Container` + `wc_get_container()->get(Class::class)` (league/container fork) | `ServiceProvider` + `Container` (league/container) | No container — global `LSCWP_CTRL` | **Skip heavy container** — lightweight `Service_Locator` or static `WPPO::get(Service::class)` suffices; avoid `league/container` dep (release ZIP bloat) |
| **Context / Subscriber** | `is_admin()` gates `WC_Admin` vs `WC_Frontend` | `SubscriberInterface::get_subscribed_events()` per feature | `LITESPEED_ON` constant + `is_admin()` | **Borrow thin `Context` helper** (`is_frontend/is_admin/is_cli/is_cron/is_rest`) — 10 lines, no subscriber bus |
| **Hook registry** | `Hooks` trait + `init_hooks()` per service | `Event_Manager` central dispatch | Hard-coded `add_action` like us | **Skip registry** — keep `add_action` explicit at `setup_hooks` (WordPress-idiomatic, grep-able) |
| **CLI** | `wp wc <resource> <action>` REST-bridged; `WC_CLI` + `WC_CLI_Tool_Command` `tool run clear_transients` | `wp rocket clean/preload/regenerate` with `make_progress_bar` + `WP_CLI::confirm` + `--confirm` alias | `wp litespeed-purge all/url/blog` with `switch_to_blog` multisite | **Borrow:** Rocket `confirm --yes/--confirm` + `make_progress_bar` (`PERF-RESEARCH.md:7`), LSCWP `switch_to_blog` for `--network` (`ECOSYSTEM-RESEARCH.md:1.2`), Woo `format_items` (`ECOSYSTEM-RESEARCH.md:1.2`) |
| **Settings** | `WC_Settings_API` + `update_option` + `WC_Admin_Settings` | `Options_Data` + `Options` value object | `LiteSpeed_Cache_Config::get_option()` | **Borrow Woo/Rocket value object**: `Settings_Service` wraps `Util::get_settings` + defaults, exposes `get($tab)` typed, not raw array |
| **Tests** | `tests/php` Brain Monkey + `WP_Mock` + `WC_Unit_Test_Case` | `tests/Unit` Brain Monkey + Mockery, CLI via `WP_CLI::runcommand` capture | `tests` simple | Already `tests/php` Brain Monkey (`AGENTS.md:17`); add `WPPO_CLI_CommandTest.php` mocking `WP_CLI` helpers (not yet, `WP-CLI-CURRENT.md:2`) |

**Anti-pattern to avoid:** Woo’s `WC_Container` pulls `league/container` + `psr/container` into `vendor/` (adds 200 KB). WPPO release ZIP (`scripts/build-release.sh` + `.distignore`) is size-sensitive; prefer hand-rolled locator over composer package.

---

## 10. Risk Matrix — Refactor Risks vs Extension Risks

| Risk | If extend only (no refactor) | If incremental refactor (B) | If big-bang (C) |
|------|------------------------------|-----------------------------|-----------------|
| **Regression on `Main:3053` version gates** (`is_wp63_plus:501` strategy defer, `is_wp69_plus:510` template buffer, `blockAssetsOnDemand:273`, `lazyLoadNative:287`) | None — untouched | Medium — `Context::is_frontend()` must not move `is_wp69_plus` checks outside `setup_hooks` timing (global `wp_version` set at `wp-settings.php` before `plugins_loaded`, so safe) | High — moving buffer `wp_template_enhancement_output_buffer:545` to new class risks `TODO #553` legacy path removal timing |
| **Multisite `switch_to_blog` isolation** (`Util:91-106` blog-keyed memo, `Util::transient_key:781` prefix, `Util:214 on_switch_blog` no-op) | None | Low — must keep `Util::ensure_settings_cache_hook:239` always-registered (`PERF-RESEARCH.md:7`) | High — new container must key per-blog |
| **LiteSpeed coexistence** (`should_bypass_for_litespeed:398`, `litespeed_can_optm:402`, `wppo_litespeed_can_cdn:Cache:1349`) | None | Low — keep `LiteSpeed_Integration::init:797` always outside context gates (`PERF-RESEARCH.md:4`) | High — new `Cache_Service` might miss LiteSpeed gate |
| **Release pipeline** (`composer install --no-dev --optimize-autoloader` at `AGENTS.md:17`, `release.yml`, `scripts/build-release.sh`, `.distignore`) | None | Medium for PSR-4 PR-D only (needs `composer dump-autoload` + lock regen) | High — lock + vendor diff large |
| **Test coverage** (no CLI tests `WP-CLI-CURRENT.md:2`, 9 Jest suites `AGENTS.md:17`) | Stays 0% CLI — future `wp wppo` regressions uncaught | Fixes — `Service` mockable via Brain Monkey `when()` | Same but larger blast radius |
| **Onboarding new `wppo_*` hooks** (`HOOK-GAPS.md` 32 gaps) | Each gap is 1 `apply_filters` patch — low risk, but drift (15 undoc hooks `HOOK-AUDIT.md:15`) grows | `Service` filter locations (`Service::should_convert`) more discoverable than `Core:1505` raw | Over-abstracted `Hook_Registry` obscures `grep -rn apply_filters` audit (272 hits) |

---

## 11. Checklist — Before Adding Next CLI/Hook Feature

- [ ] Run `wc -l includes/class-main.php` — if >3100, extract one `File_Optimisation_Service` method before adding more state there.
- [ ] Run `grep -rn "add_action|add_filter" includes/class-main.php | wc -l` — if >50 unconditional, gate one via `Context::is_frontend()` (P10) before adding another `add_action` there.
- [ ] For new CLI subcommand: delegate to `Service::method()` returning DTO, format via `WP_CLI\Formatter` (`ECOSYSTEM-RESEARCH.md:1.2`), gate destructive with `WP_CLI::confirm + --yes` (`PERF-RESEARCH.md:8`), add `make_progress_bar` if loop >50 items, expose `--dry-run` + `--format`.
- [ ] For new `wppo_*` hook: add `@since NEXT`, prefix `wppo_` (not `wppo_cli_` unless CLI-only), document in `docs/hooks.md` with `@param` types, check `HOOK-GAPS.md` gap ID, keep default `true`/`[]` so `has_filter` short-circuit (`PERF-RESEARCH.md:2.2` applies).
- [ ] Keep `Util:145` memo + `Util:752` home_url memo + `switch_blog:214` always-registered; keep `advanced-cache.php` drop-in zero-hook (`PERF-RESEARCH.md:2.2` `advanced-cache.php` row).
- [ ] Verify with `npm run lint:js → composer lint → npm test → npm run build` (`AGENTS.md:38` required order) + `composer test` Brain Monkey; add `tests/php/WPPO_CLI_CommandTest.php` with `when('WP_CLI::success')->justReturn()` mock.

---

## 12. Sources (Every Claim `file:line`)

- Bootstrap + Main: `performance-optimisation.php:41-44` autoload + `new Main`, `class-main.php:169-357` ctor defaults, `436-475` `includes()` 11 `file_exists`/`require_once`, `472-474` `WP_CLI::add_command`, `485-799` `setup_hooks` 314 lines, `501-510` version gates, `539-574` cache conditional, `599-612` combineCSS, `655-680` minify, `758-760` preload/speculation/hints, `762-765` helpers, `1032-1131` `on_settings_update`, `1252` `wppo_server_timing_enabled`, `1495-1618` admin localize.
- Util/Cache: `class-util.php:43-58` `ALLOWED_SETTINGS_KEYS`, `91-106` settings memo, `87` home_url cache, `752-768` `cached_home_url`, `781` `transient_key`, `877` sanitize, `class-cache.php:40` `CACHE_DIR`, `408-562` combine, `750-1110` inline budget, `980` `wppo_inline_combined_css`, `1143` `wppo_skip_combine`, `1243` `process_buffer_only`, `1331-1414` CDN, `1482-1520` `is_not_cacheable`, `1505:G-01`, `1661` `wppo_cache_page_html`, `1741:G-02`, `1838:G-03`, `2032`/`2074` cache clear actions.
- CLI/REST/Cron: `class-wppo-cli-command.php:12-42` namespace, `69,168,315,442,567,795,896,954` `@when`, `75,174,321,573,801,902,956` subcommands, `86,103,117,180,215,325,451,864` service calls, `class-rest.php:58-260` routes, `357-361` permission, `464` update_settings, `819` database_cleanup, `1025` object_cache, `1104` redis 10-key, `class-cron.php:57-74` hooks, `99` `every_5_hours`, `114` schedule, `283-351` preload batch `200`/`0-1800`/`500`, `496` deadline `15s`, `49` `TO_FETCH_LIMIT 50`, `666` `wppo_cron_discovery_limit`, `class-object-cache.php:67` dropin path, `252` enable.
- Docs: `AGENTS.md:18-19` manual load no PSR-4, `docs/hooks.md:42` public hooks, `HOOK-AUDIT.md:0-11` 272 hits, `HOOK-GAPS.md:25-58` 32 gaps P0-P3, `PERF-RESEARCH.md:1-9` context cost + PR split, `ECOSYSTEM-RESEARCH.md:1.2` handbook matrix, `WP-CLI-CURRENT.md:3` per-subcommand, `WP-CLI-RESEARCH.md:5-10` exit/format/progress, `composer.json:19-21` `autoload.classmap`.

*Research-only — no production edits. For implementation, follow `PERF-RESEARCH.md:9` PR-A→F order and `HOOK-GAPS.md` §2 detailed gap specs.*

