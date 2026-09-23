# Load Order — Performance Optimisation Plugin

Evidence baseline: ARCH-001 (2026-09-23). Verified against
`performance-optimisation.php`, `Main::includes()` (`includes/class-main.php`),
`composer.json`, `tests/php/bootstrap.php`, `uninstall.php`, `templates/object-cache.php`.

## 1. Plugin bootstrap (every request with the plugin active)

1. `performance-optimisation.php`: defines `WPPO_PLUGIN_PATH/URL/VERSION`,
   `WPPO_REQUIRES_PHP` (8.2), `WPPO_REQUIRES_WP` (6.2); declares
   `wppo_get_wp_version()` + runtime guard.
2. Requires `vendor/autoload.php` when present. When missing: registers an
   `admin_notices`/`network_admin_notices` notice and keeps the site running
   unoptimised (fail-open frontend, audit #1362).
3. Stale-classmap fallback: if `PerformanceOptimise\Inc\Loader_Map` is still
   unknown, requires `includes/class-loader-map.php` directly, then if
   `PerformanceOptimise\Inc\Main` is still unknown, requires
   `includes/class-main.php` directly, then `new Main()` (only when
   deps present and the version guard passes).
4. `register_activation_hook` → `wppo_activate()` → `Activate::init()`.
   `register_deactivation_hook` → `wppo_deactivate()` → `Deactivate::init()`.

## 2. `Loader_Map` + `Main::includes()` (manual loading — no PSR-4, watchdog-protected)

Single owner for "which file provides which class":
`PerformanceOptimise\Inc\Loader_Map` (`includes/class-loader-map.php`,
ARCH-003) owns the eager-load file list, the lazy fallback
short-name-to-file map, and the WP-CLI file path — all built on
`WPPO_PLUGIN_PATH . 'includes/'`. `Main::includes()` is a thin delegate:
it requires `Loader_Map` first, loops `Loader_Map::eager_files()`, applies
its own `should_load_litespeed_stack()` decision to
`Loader_Map::litespeed_stack_files()`, registers one `spl_autoload_register`
closure that resolves via `Loader_Map::path_for()`, and wires the `WP_CLI`
block via `Loader_Map::cli_file()`. Future directory moves (ARCH-013) touch
exactly `Loader_Map` (+ the entry-point chain below).

Eager `require_once` (each guarded by `file_exists`):
`Wp_Version` → Action Scheduler vendor lib (not under `includes/`, still wired
directly in `Main`) → `Server_Rules` → `Header_Emitter` →
`LiteSpeed_Integration` → conditionally `LiteSpeed_Crawler` + `LiteSpeed_ESI`
(only when `should_load_litespeed_stack()`; non-LiteSpeed frontends skip parse
cost, fail-open) → `Llms` → `OD_Bridge` → `Bfcache` → `Perf_Translations` →
`AI_Adaptive` → `Edge_Cache` → `trait-purge-logger.php` → `Edge_Purger` →
`CDN` → `Builder_Purge_Watcher` → `Hook_Registry`.

Lazy: one `spl_autoload_register` fallback map resolves the remaining
`PerformanceOptimise\Inc\*` classes from `includes/<file>` on first
missing-class use (covers stale/partial classmaps; eager classes omitted to
avoid duplicate probes, except the map entries `Main` keeps for late
callers: Crawler + ESI when the eager load was skipped, plus `Hook_Registry`,
`Purge_Logger`, `Wp_Version`, and the self-entries `Loader_Map`, `Main`,
`WPPO_CLI_Command` so every inventory class resolves through one map).

`WP_CLI` only: requires `class-wppo-cli-command.php` and registers `wp wppo`
(7 subcommands).

## 3. Composer classmap

`composer.json` `autoload.classmap: ["includes/"]` — recursive, so future
subdirectories resolve after a dump, BUT: `Loader_Map` hardcodes flat
`includes/<file>` paths in both the eager list and the fallback map. Any file
move MUST update the loader map in the same change (ARCH-003 centralised this
into `includes/class-loader-map.php`; ARCH-013 performs moves). Release builds
use `--optimize-autoloader`.

## 4. Hooks / runtime surfaces

- `Main::__construct` → `includes()` → collaborator registration →
  `Hook_Registry` (REF-005; `setup_hooks()` is a 3-line delegate).
- REST (`class-rest.php`, 40 routes, namespace `performance-optimisation/v1`)
  loads lazily; routes register on `rest_api_init`. Gate: `manage_options` +
  `X-WP-Nonce`, except public `rum_collect` (token + IP rate limit).
- Cron/Action Scheduler: `Cron` registers WP-Cron hooks; payloads enqueue via
  `Scheduler` boundary (full consolidation: ARCH-012).
- Admin SPA: `src/index.js` + `src/lazyload.js` → committed `build/` output.

## 5. Activation / deactivation / uninstall

- Activation (`Activate::init`): `dbDelta` creates `{prefix}wppo_activity_logs`;
  `.htaccess` rules skipped on LiteSpeed (`Server_Rules::should_skip_htaccess_write`);
  requires `wp-admin/includes/upgrade.php`.
- Deactivation (`Deactivate::init`): clears scheduled hooks, removes drop-ins
  it owns; leaves settings for re-activation.
- Uninstall (`uninstall.php`, `WP_UNINSTALL_PLUGIN` guard): one static-guarded
  network-file cleanup (cache dir, `wppo/` images, redis config + circuit
  sidecars, drop-ins, `.htaccess` marker) + per-site loop over 29 known
  `wppo_*` options (verify gate: `uninstall` check).

## 6. Drop-ins (loaded by WordPress core, NOT by `includes()`)

- `advanced-cache.php`: created/detected/removed by `Advanced_Cache_Handler`;
  serves `wp-content/cache/wppo/{domain}/{path}/index.html` before WP boots.
- `wp-content/object-cache.php`: copied from `templates/object-cache.php`;
  custom `WP_Object_Cache` (standalone/sentinel/cluster), keyed with
  `get_current_blog_id()` namespacing. Core loads it before plugins — the PHPUnit
  bootstrap therefore requires the template early, before Brain Monkey can
  eval-declare `wp_cache_*` stubs.

## 7. Multisite notes

`Util::transient_key()` prefixes `{blog_id}_`; static HTML cache uses
domain-based dirs (inherently safe); options are site-native; uninstall iterates
sites with `switch_to_blog()`. Moved memos MUST stay blog-keyed (precedent:
REF-003/REF-004 parity fixes).

## 8. Test bootstrap (`tests/php/bootstrap.php`)

Normalises cwd for `patchwork.json` → `vendor/autoload.php` → early
`templates/object-cache.php` → in-memory `wp_object_cache` miss-on-read stub
(`ObjectCacheTest` swaps a richer stub). Test files must be `*Test.php`
matching the class name; SUT files must NOT be top-level `require_once`d
(breaks Patchwork mocking); classes with own `tearDown()` alias the trait
method (`bootstrap_teardown` pattern).
