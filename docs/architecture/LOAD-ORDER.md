# Load Order and Autoload Boundaries

Phase 3 verification baseline: 2026-09-24
Runtime source: `origin/master` commit `72818289c9745ed58c17bf3ab418f4d20953b657`
Loader owner: `includes/Core/class-loader-map.php`

The plugin uses two autoload mechanisms:

1. Composer generates a recursive classmap for `includes/` and loads vendor packages.
2. `Loader_Map` and `Main::includes()` provide explicit runtime loading and stale-classmap recovery.

The watchdog forbids a PSR-4 conversion. Phase 3 may improve the classmap or fallback map, but it will not replace manual loading.

## 1. Plugin entry

`performance-optimisation.php` performs these steps:

1. Define `WPPO_PLUGIN_PATH`, `WPPO_PLUGIN_URL`, `WPPO_VERSION`, and runtime floors.
2. Define guarded version-floor functions.
3. Return before boot when `WPPO_UNIT_TESTS` is active.
4. Load `vendor/autoload.php` when present.
5. Register a fail-open missing-dependency notice otherwise.
6. Check runtime floors.
7. Require `includes/Core/class-loader-map.php` when `Loader_Map` remains unknown.
8. Require `includes/Core/class-main.php` when `Main` remains unknown.
9. Construct `Main` when the class exists.
10. Register activation and deactivation callbacks.

The stale-classmap chain is deliberate. Composer and `Loader_Map` can each satisfy normal loads; the direct requires protect deployments with stale generated classmaps.

## 2. Main and Loader_Map

`Main::includes()` owns orchestration decisions. `Loader_Map` owns path data.

The loading sequence is:

1. Require `Loader_Map` when needed.
2. Require the Action Scheduler library from `vendor/woocommerce/action-scheduler/action-scheduler.php` when it remains unavailable.
3. Require every file in `Loader_Map::eager_files()`.
4. Apply `Main::should_load_litespeed_stack()`.
5. Require `Loader_Map::litespeed_stack_files()` only for the true branch.
6. Register one `spl_autoload_register` callback that calls `Loader_Map::path_for()`.
7. Require `Loader_Map::cli_file()` and register the WP-CLI command under `WP_CLI`.

### Eager files

`Loader_Map::eager_files()` loads these files in order:

1. `Core/class-wp-version.php`
2. `Edge/class-server-rules.php`
3. `Edge/class-header-emitter.php`
4. `Integrations/class-litespeed-integration.php`
5. `Compatibility/class-llms.php`
6. `Insight/class-od-bridge.php`
7. `Cache/class-bfcache.php`
8. `Admin/class-perf-translations.php`
9. `Insight/class-ai-adaptive.php`
10. `Insight/class-ai-anomaly.php`
11. `Edge/class-edge-cache.php`
12. `Support/trait-purge-logger.php`
13. `Edge/class-edge-purger.php`
14. `Edge/class-cdn.php`
15. `Integrations/class-builder-purge-watcher.php`
16. `Core/class-hook-registry.php`

The LiteSpeed-only branch then loads:

1. `Integrations/class-litespeed-crawler.php`
2. `Integrations/class-litespeed-esi.php`

Action Scheduler remains a deliberate direct require outside `Loader_Map` path data. Moving that require into data would change the vendor bootstrap contract without architectural benefit.

## 3. Lazy class loading

`Loader_Map::fallback_map()` maps 68 `PerformanceOptimise\Inc` class names to canonical files under:

```text
includes/<Domain>/class-<name>.php
includes/class-util.php
```

`Loader_Map` provides:

- `base_dir()` for the plugin includes path;
- `file_path()` for one-level canonical path validation;
- `path_for()` for short and fully qualified plugin class names;
- `cli_file()` for the WP-CLI command;
- `allowed_dirs()` for the 14 runtime domain directories.

The fallback autoloader covers the full Loader_Map-managed plugin class set. It does not own the three protected minify wrappers or the Redis drop-in class because WordPress or `Main` loads those through separate contracts.

## 4. Composer classmap

`composer.json` contains:

```json
"autoload": {
    "classmap": ["includes/"]
}
```

Composer recursively discovers plugin classes and maps vendor packages through its normal autoloader. The generated local classmap contains the plugin classmap plus three minify wrappers. No plugin PSR-4 namespace mapping exists.

Composer provides the normal load path. `Loader_Map` provides deterministic path ownership and stale-classmap recovery. Release builds run an optimized Composer dump.

A directory move must update:

- the file itself;
- `Loader_Map` eager and fallback data;
- Composer autoload output when generated locally;
- inventory and graph artifacts;
- tests and documentation with old paths;
- installed drop-ins when the moved file participates in early loading.

## 5. REST loading

`Rest` loads lazily through `Loader_Map`. `Rest::register_routes()` registers the `performance-optimisation/v1` namespace during `rest_api_init`.

The installed site exposes 48 registered patterns including the namespace root, which represents 47 concrete endpoints. Administrative routes require `manage_options` and REST nonce validation. The public `rum_collect` route keeps its token, IP, and global rate-limit checks.

`Rest_Cache` and `Rest_Settings` also load lazily. Current route callbacks point directly to service instances; compatibility proxies remain for direct callers and tests.

## 6. WP-CLI loading

Under `WP_CLI`, `Main::includes()` requires `Admin/class-wppo-cli-command.php` and registers `wp wppo`.

The live command exposes eight subcommands:

1. `cache`
2. `database`
3. `image`
4. `object-cache`
5. `pagespeed`
6. `settings`
7. `system-info`
8. `verify`

The old “7 subcommands” documentation counted the pre-`verify` surface.

## 7. Cron and Action Scheduler

`Cron` loads lazily and registers WP-Cron hooks. `Scheduler` owns shared Action Scheduler and WP-Cron primitives.

Action Scheduler's vendor library loads before plugin classes. The plugin still has fragmented job ownership:

- `Cron::AS_HOOKS` drives part of deactivation and uninstall cleanup;
- `Builder_Purge_Watcher` schedules drift and upgrade purge hooks;
- RUM and `Object_Cache` schedule some Cron-owned hooks directly.

Phase 3 will centralize job ownership without changing the vendor require order.

## 8. Object-cache drop-in

WordPress loads `/wp-content/object-cache.php` before regular plugins. The repository template lives at `templates/object-cache.php` and defines `WP_Object_Cache` with blog-aware key namespacing.

`Object_Cache` and the drop-in load the procedural Redis helper from:

```text
includes/Support/redis-connect-helper.php
```

### Installed drift

The installed `/wp-content/object-cache.php` still searches the pre-ARCH-013 path:

```text
includes/redis-connect-helper.php
```

That deployed file also predates later template hardening. Every CLI probe logs:

```text
wppo_redis_connect() not found
```

The repository template and `Object_Cache` class use the current path. `wp wppo verify` checks drop-in ownership, not byte parity. P3-001 records this installed-state defect. The post-merge refresh must copy the owned template through the plugin's safe install path or a reviewed equivalent, then re-run Redis and object-cache smoke checks.

`tests/php/bootstrap.php` requires the repository template, so PHPUnit does not detect deployed template drift. A later verification improvement should compare the installed drop-in with the repository template without exposing Redis credentials or unrelated site data.

## 9. Advanced-cache drop-in

`Advanced_Cache_Handler` owns the `advanced-cache.php` lifecycle. WordPress loads that drop-in before normal plugins, and it serves the static HTML cache without booting WordPress for a hit.

The early-load contract and cache directory layout stay protected. Loader refactors must not move this drop-in behavior behind a plugin class that cannot load at `advanced-cache.php` time.

## 10. React and frontend assets

`src/index.js`, `src/lazyload.js`, `src/main.js`, `src/rum.js`, and `src/esi.js` build through `@wordpress/scripts`. The committed `build/` directory contains the admin SPA, lazy loader, admin-bar controls, RUM, and ESI bundles.

Any JavaScript or SCSS change requires:

```sh
npm run build
```

and a committed `build/` diff. The loader does not affect PHP class order.

## 11. Test bootstrap

`tests/php/bootstrap.php`:

1. normalizes the project root for Patchwork;
2. loads `vendor/autoload.php`;
3. requires `templates/object-cache.php` before Brain Monkey defines cache functions;
4. installs a minimal in-memory object cache;
5. defines plugin and WordPress test constants;
6. installs a test `$wpdb` shape;
7. exposes `WPPO_Test_Bootstrap` for Brain Monkey setup and targeted static reset calls.

The bootstrap uses synthetic `/tmp/wordpress/` paths and defines `WPPO_VERSION` as `2.0.0`. It does not replace installed-site verification. Phase 3 must extend reset coverage through a production-owned runtime-state registry rather than add more scattered test-only calls.

## 12. Activation, deactivation, and uninstall

- `Activate::init()` creates the activity table and installs owned rules/drop-ins through domain owners.
- `Deactivate::init()` clears scheduled work and removes owned artifacts.
- `uninstall.php` runs under `WP_UNINSTALL_PLUGIN`, removes network and per-site data, and iterates multisite sites when needed.

Job teardown must use one canonical hook registry. The current builder upgrade purge hook is the first known omission.

## 13. Verification after loader changes

A loader or path change requires all of these:

```sh
composer dump-autoload --optimize
php scripts/generate-class-inventory.php --check
vendor/bin/phpunit tests/php/LoaderMapTest.php
composer test
wp wppo verify
```

Runtime smoke must cover plugin activation, frontend, admin, REST, CLI, cron, cache, and any touched drop-in. The installed Redis drop-in parity check is mandatory until the deployed copy is refreshed.
