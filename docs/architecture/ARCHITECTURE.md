# Architecture — Performance Optimisation Plugin

Owner: Phase 2 architecture campaign. Evidence baseline: ARCH-001 (2026-09-23).
Companion docs: `LOAD-ORDER.md`, `INCLUDE-HIERARCHY.md`, `DEPENDENCY-GRAPH.json`,
`class-inventory.json` (regenerate with `php scripts/generate-class-inventory.php`),
`BOUNDARIES.md`, `REFACTORING-RULES.md`. Watchdog `wppo-agent-rules.md` wins on conflicts.

## System map (from evidence)

```text
performance-optimisation.php          plugin entry: constants, guards, Composer autoload, Main boot
  └─ includes/Core/class-main.php    orchestration: collaborators, Hook_Registry, migrations, admin, CLI
       ├─ includes/Cache domain      Cache (buffer+policy), Cache_Key, Filesystem, Url, Woo_Detect,
       │                             Scheduler, Http, Wp_Version (Phase 1 boundaries + facades in Util)
       ├─ Asset pipeline            Main (defer/delay), Cache (CSS combine), Image_Optimisation,
       │                             Img_Converter, Google_Fonts, Used_CSS, Critical_CSS, Css_Safelist
       ├─ Data / insight            Database_Cleanup, Telemetry, Pagespeed, Suggestion_Engine,
       │                             System_Info, RUM, AI_Adaptive, OD_Bridge
       ├─ Delivery / edge           CDN (+CDN_Purger), Edge_Cache, Edge_Purger, Cloudflare_Purger,
       │                             Server_Rules, Htaccess_Handler, Advanced_Cache_Handler,
       │                             LiteSpeed_Integration (+Crawler, +ESI), Bfcache, Header_Emitter
       ├─ Surface                   Rest (40 routes), Abilities, Metabox, Asset_Manager,
       │                             Admin_Notices, Sandbox_Preview, WPPO_CLI_Command
       └─ Lifecycle                 Activate, Deactivate, uninstall.php, Cron, Builder_Purge_Watcher,
                                     Llms, Perf_Translations, Log, Core_Tweaks, Object_Cache
```

Static analysis counts 322 cross-class `ClassName::` edges across 54 of 57 files
(`DEPENDENCY-GRAPH.json`). The densest hubs are `Util::` (settings, transient keys,
filesystem, URL — now facades over Phase 1 boundaries) and `Main` (orchestration).

## Baseline metrics (ARCH-001, 2026-09-23)

| Class | Lines | Methods |
|-------|-------|---------|
| Main | 14173 | 232 |
| Image_Optimisation | 10966 | 165 |
| Critical_CSS | 7594 | 137 |
| Cache | 7304 | 137 |
| AI_Adaptive | 6322 | 98 |
| Util | 4986 | 155 |
| Used_CSS | 4960 | 94 |
| Rest | 4494 | 73 |
| Img_Converter | 4078 | 66 |
| Database_Cleanup | 3354 | 58 |
| RUM | 3349 | 63 |
| Object_Cache | 3201 | 54 |
| LiteSpeed_Integration | 2798 | 58 |
| Cron | 1847 | 37 |

React: `FileOptimization.js` 6201 lines, `Dashboard.js` 2168 lines, `App.js` 654 lines.
`includes/` root: 56 class/trait files (+ `minify/` wrappers, `redis-connect-helper.php`).

## Dependency direction (enforced)

```text
Bootstrap / Core (entry, Main, Hook_Registry, loader)
       ↓
Infrastructure / Boundaries (Settings_Store, Filesystem, Url, Cache_Key,
  Scheduler, Http, Wp_Version, Woo_Detect)
       ↓
Domain / Feature Services (Cache, Images, CSS, Database, Edge, Integrations)
       ↓
Presentation (Rest, Admin, CLI, React SPA)
```

Rules: boundaries never call features; features never reach into another feature's
private implementation; `Main` stays orchestration; `Util` stays compatibility
facade (new code uses the owning boundary directly).

## Known coupling hotspots (queue fuel, not scope)

- `Main`: ~15 `maybe_migrate_*` settings migrations; defer/delay script clusters;
  Elementor detection cluster (ARCH-004/005 candidates).
- `Cache`: CSS-combine + inline-budget cluster; invalidation/purge-fallback cluster
  (ARCH-006/007 candidates).
- `Used_CSS` ↔ `Critical_CSS`: shared checksum/safelist/exclusion helpers already
  cross-referenced via `class_exists` guards (ARCH-009 candidate).
- `Rest`: 73 methods / 40 routes, single registrar (ARCH-011 candidate).

## Protected (do NOT redesign)

LiteSpeed ESI bridge + coexistence modes + header protocol (owner WONTFIX #1291);
manual `Main::includes()` loading (no PSR-4); `useState`-only SPA (no router/store);
`advanced-cache.php` drop-in contract; Redis drop-in key namespacing; uninstall behavior.
