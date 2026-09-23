# Include Hierarchy — current state (ARCH-001)

Evidence baseline: 2026-09-23. 57 files flat in `includes/` (56 class/trait
files + `redis-connect-helper.php`, plus `minify/` wrappers). Counts/methods/refs:
`class-inventory.json`; edges: `DEPENDENCY-GRAPH.json`. Canonical redesign: ARCH-002.

## Current flat layout by responsibility (one-line owners)

- **Core/bootstrap:** `Main`, `Hook_Registry`, `Wp_Version`, `Activate`, `Deactivate`
- **Cache:** `Cache`, `Cache_Key`, `Advanced_Cache_Handler`, `Bfcache`
- **Settings:** `Settings_Store`, `Sandbox_Preview`
- **Filesystem/URL/HTTP:** `Filesystem`, `Url`, `Http`, `Scheduler`
- **Assets:** `Asset_Manager`, `Css_Safelist`, `Google_Fonts`
- **Images:** `Image_Optimisation`, `Img_Converter`
- **CSS:** `Critical_CSS`, `Used_CSS`
- **Database/insight:** `Database_Cleanup`, `Telemetry`, `Pagespeed`,
  `Suggestion_Engine`, `System_Info`, `RUM`, `AI_Adaptive`, `OD_Bridge`
- **Edge/delivery:** `CDN`, `CDN_Purger`, `Edge_Cache`, `Edge_Purger`,
  `Cloudflare_Purger`, `Server_Rules`, `Htaccess_Handler`, `Header_Emitter`
- **LiteSpeed:** `LiteSpeed_Integration`, `LiteSpeed_Crawler`, `LiteSpeed_ESI`
- **Compat/detect:** `Woo_Detect`, `Core_Tweaks`, `Builder_Purge_Watcher`, `Llms`
- **Surface:** `Rest`, `Abilities`, `Metabox`, `Admin_Notices`,
  `Perf_Translations`, `WPPO_CLI_Command`, `Object_Cache`
- **Shared:** `Util` (facade), `Log`, `Purge_Logger` (trait)

## Move-safety constraints (every future move must clear ALL of these)

1. `Main::includes()` eager paths + `spl_autoload` fallback map hardcode
   `includes/<file>` — update loader in the same change (ARCH-003 first).
2. `composer.json` classmap covers `includes/` recursively, but release/stale
   classmaps need a fresh dump; never rely on classmap alone.
3. `require`/`include`/`class_exists`/`trait_exists`/`ReflectionClass`/
   `__DIR__`/`WPPO_PLUGIN_PATH` sweeps across PHP, JS, build scripts, Composer,
   tests, Actions, docs, release scripts.
4. Entry-point stale-classmap fallback (`performance-optimisation.php` requires
   `includes/class-main.php` directly).
5. Test bootstrap expectations (no top-level SUT `require_once`; `*Test.php`
   naming; trait `tearDown` alias pattern).
6. Committed `build/` assets: keep `--ours`, rebuild, commit (never hand-edit).
7. Never commit `vendor/`, `node_modules/`, worktree symlinks, temp files.

## Generator limitations (accepted)

- Refs count static `ClassName::` occurrences only; `class_exists('...')` /
  `method_exists()` string guards (common in `Used_CSS`, `Rest`) are invisible.
- Method counts use leading-visibility `function` lines (matches campaign baseline).
- Hook lists capture literal `add_action`/`add_filter('name')` calls only.
- Regenerate: `php scripts/generate-class-inventory.php`; drift gate:
  `php scripts/generate-class-inventory.php --check`.
