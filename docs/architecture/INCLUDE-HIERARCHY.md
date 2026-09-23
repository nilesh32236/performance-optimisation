# Include Hierarchy — current state (ARCH-001)

Evidence baseline: 2026-09-23. 60 files flat in `includes/` (59 class/trait
files + `redis-connect-helper.php`, plus `minify/` wrappers). Counts/methods/refs:
`class-inventory.json`; edges: `DEPENDENCY-GRAPH.json`. Canonical redesign: ARCH-002.

## Current flat layout by responsibility (one-line owners)

- **Core/bootstrap:** `Main`, `Hook_Registry`, `Wp_Version`, `Activate`, `Deactivate`
- **Cache:** `Cache`, `Cache_Key`, `Advanced_Cache_Handler`, `Bfcache`
- **Settings:** `Settings_Store`, `Sandbox_Preview`, `Settings_Migrations`
- **Filesystem/URL/HTTP:** `Filesystem`, `Url`, `Http`, `Scheduler`
- **Assets:** `Asset_Manager`, `Css_Safelist`, `Google_Fonts`, `Script_Strategy` (ARCH-005 defer/delay cluster from `Main`)
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

## Canonical tree (decided ARCH-002)

Decision date: 2026-09-23. Decided from ARCH-001 evidence
(`ARCHITECTURE.md` system map + dependency direction + coupling hotspots,
322 cross-class edges in `DEPENDENCY-GRAPH.json`, per-file counts in
`class-inventory.json`, loader paths in `LOAD-ORDER.md`). Docs-only decision;
no file moves until ARCH-013, no loader edits until ARCH-003. Class names,
namespaces, behavior, and load order are unchanged by construction.

```text
includes/
  Core/           class-main.php, class-hook-registry.php, class-wp-version.php,
                  class-activate.php, class-deactivate.php
  Cache/          class-cache.php, class-cache-key.php,
                  class-advanced-cache-handler.php, class-bfcache.php,
                  class-object-cache.php
  Settings/       class-settings-store.php, class-sandbox-preview.php,
                  class-settings-migrations.php
  Scheduler/      class-scheduler.php, class-cron.php
  Support/        class-filesystem.php, class-url.php, class-http.php,
                  class-log.php, trait-purge-logger.php,
                  redis-connect-helper.php
  Assets/         class-asset-manager.php, class-css-safelist.php,
                  class-google-fonts.php
  Images/         class-image-optimisation.php, class-img-converter.php
  CSS/            class-critical-css.php, class-used-css.php
  Database/       class-database-cleanup.php
  Insight/        class-telemetry.php, class-pagespeed.php,
                  class-suggestion-engine.php, class-system-info.php,
                  class-rum.php, class-ai-adaptive.php, class-od-bridge.php
  Edge/           class-cdn.php, class-cdn-purger.php, class-edge-cache.php,
                  class-edge-purger.php, class-cloudflare-purger.php,
                  class-server-rules.php, class-htaccess-handler.php,
                  class-header-emitter.php
  Integrations/   class-litespeed-integration.php, class-litespeed-crawler.php,
                  class-litespeed-esi.php, class-woo-detect.php,
                  class-builder-purge-watcher.php
  Admin/          class-rest.php, class-abilities.php, class-metabox.php,
                  class-admin-notices.php, class-perf-translations.php,
                  class-wppo-cli-command.php
  Compatibility/  class-core-tweaks.php, class-llms.php
  class-util.php            (stays at root until ARCH-014 — see open points)
  minify/                   (unchanged — out of scope, see open points)
```

Flat rule: no deeper nesting than one level. Every target below was checked
against current load references (`Main::includes()` eager list + fallback map,
drop-in contracts, test bootstrap); moves in ARCH-013 update the loader map
(ARCH-003) in the same change per the move-safety constraints above.

### Per-class target mapping (59 files, evidence per row)

Evidence keys: `[sysmap:<cluster>]` = `ARCHITECTURE.md` system-map cluster;
`[hotspot]` = `ARCHITECTURE.md` coupling hotspot; `[dir]` = dependency-direction
layer (`ARCHITECTURE.md` "Dependency direction"); `[graph]` = `DEPENDENCY-GRAPH.json`
edge/cluster evidence; `[load]` = `LOAD-ORDER.md` loader/drop-in evidence.

| File | Target | Evidence |
|------|--------|----------|
| `class-main.php` | `Core/` | [sysmap:orchestration] entry + `Main::includes()` owner; [dir] Bootstrap/Core layer |
| `class-hook-registry.php` | `Core/` | [sysmap:orchestration] `Hook_Registry` (REF-005 delegate of `Main::setup_hooks`); [dir] Bootstrap/Core |
| `class-wp-version.php` | `Core/` | [sysmap:Cache-domain/Wp_Version] Phase-1 boundary + REF-010 central gate; core-version guard belongs with bootstrap; [dir] Infrastructure |
| `class-activate.php` | `Core/` | [sysmap:Lifecycle] activation; [load] `register_activation_hook → Activate::init()` |
| `class-deactivate.php` | `Core/` | [sysmap:Lifecycle] deactivation; [load] `register_deactivation_hook → Deactivate::init()` |
| `class-cache.php` | `Cache/` | [sysmap:Cache domain] buffer+policy; [hotspot] ARCH-006/007 extraction source; [graph] dense `Cache::` hub |
| `class-cache-key.php` | `Cache/` | [sysmap:Cache domain] Phase-1 boundary (REF-001 extraction from Util); [graph] `Cache_Key::` edges from cache paths |
| `class-advanced-cache-handler.php` | `Cache/` | [sysmap:Delivery/edge] drop-in owner; [load] drop-in contract (`advanced-cache.php` create/detect/remove) — cache-serving policy |
| `class-bfcache.php` | `Cache/` | [sysmap:Delivery/edge] bfcache (logged-in cache policy); cache-behavior owner |
| `class-object-cache.php` | `Cache/` | [sysmap:Lifecycle/Object_Cache] Redis manager; [load] drop-in-adjacent (`templates/object-cache.php`, `wppo-redis-config.php`); cache backend |
| `class-settings-store.php` | `Settings/` | [sysmap:Infrastructure] Phase-1 boundary (REF-002/REF-011); [dir] Infrastructure/Boundaries |
| `class-settings-migrations.php` | `Settings/` | [sysmap:Lifecycle] one-time `maybe_migrate_*` backfills relocated verbatim from `Main` (ARCH-004); `Main` keeps facade proxies so hook-callback identity is unchanged; [load] lazy via `Loader_Map` fallback map |
| `class-sandbox-preview.php` | `Settings/` | [sysmap:Surface] staged sandbox settings; settings-domain staging owner |
| `class-scheduler.php` | `Scheduler/` | [sysmap:Cache-domain/Scheduler] Phase-1 boundary (REF-014 Action-Scheduler + stampede locks); [dir] Infrastructure |
| `class-cron.php` | `Scheduler/` | [sysmap:Lifecycle/Cron] WP-Cron registration; ARCH-012 consolidates payloads onto `Scheduler` primitives |
| `class-filesystem.php` | `Support/` | [sysmap:Cache-domain/Filesystem] Phase-1 boundary (REF-003/REF-012); [dir] Infrastructure |
| `class-url.php` | `Support/` | [sysmap:Cache-domain/Url] Phase-1 boundary (REF-004); [dir] Infrastructure |
| `class-http.php` | `Support/` | [sysmap:Cache-domain/Http] Phase-1 boundary (REF-015 teardown helpers); [dir] Infrastructure |
| `class-log.php` | `Support/` | [sysmap:Lifecycle/Log] activity-logging infrastructure; cross-domain support |
| `trait-purge-logger.php` | `Support/` | [sysmap:Delivery/edge] shared purge-logging trait; [load] eager `require_once` in `Main::includes()`; support trait |
| `redis-connect-helper.php` | `Support/` | Support helper (connect/parse/serializer functions); loaders confirmed — see open points |
| `class-asset-manager.php` | `Assets/` | [sysmap:Surface/Asset_Manager] per-page script/style manager; asset-pipeline owner |
| `class-css-safelist.php` | `Assets/` | [sysmap:Asset pipeline] `Css_Safelist`; shared CSS exclusion owner alongside Used/Critical CSS |
| `class-google-fonts.php` | `Assets/` | [sysmap:Asset pipeline] `Google_Fonts` self-host; asset-pipeline owner |
| `class-script-strategy.php` | `Assets/` | [sysmap:Asset pipeline] `Script_Strategy` defer rendering + delay-JS decisions/data relocated from `Main` (ARCH-005); `Main` keeps facade proxies so hook-callback identity is unchanged; [load] lazy via `Loader_Map` fallback map |
| `class-image-optimisation.php` | `Images/` | [sysmap:Asset pipeline] image serving/lazy/hero-preload; [hotspot] ARCH-008 extraction source; [graph] dense hub |
| `class-img-converter.php` | `Images/` | [sysmap:Asset pipeline] `Img_Converter` (WebP/AVIF); image-domain service |
| `class-critical-css.php` | `CSS/` | [sysmap:Asset pipeline] per-template critical CSS; [hotspot] ARCH-009 shared-storage owner candidate |
| `class-used-css.php` | `CSS/` | [sysmap:Asset pipeline] per-URL used CSS; [hotspot] ARCH-009 (`class_exists` cross-refs with Critical_CSS) |
| `class-database-cleanup.php` | `Database/` | [sysmap:Data/insight] `Database_Cleanup` (9 cleanup ops); single-class domain |
| `class-telemetry.php` | `Insight/` | [sysmap:Data/insight] local cURL performance scanner; insight source |
| `class-pagespeed.php` | `Insight/` | [sysmap:Data/insight] PageSpeed API + scheduler job; insight source |
| `class-suggestion-engine.php` | `Insight/` | [sysmap:Data/insight] suggestions from telemetry + PageSpeed; insight consumer |
| `class-system-info.php` | `Insight/` | [sysmap:Data/insight] environment facts; insight source |
| `class-rum.php` | `Insight/` | [sysmap:Data/insight] real-user Web Vitals beacon; insight source |
| `class-ai-adaptive.php` | `Insight/` | [sysmap:Data/insight] heuristic auto-tune; [hotspot] ARCH-010 decomposition source |
| `class-od-bridge.php` | `Insight/` | [sysmap:Data/insight] Optimization Detective bridge (real-visit LCP); insight source |
| `class-cdn.php` | `Edge/` | [sysmap:Delivery/edge] CDN mapping + URL rewrite; [graph] `CDN::` edges from cache/edge paths |
| `class-cdn-purger.php` | `Edge/` | [sysmap:Delivery/edge] CDN purge fan-out; edge-purge owner |
| `class-edge-cache.php` | `Edge/` | [sysmap:Delivery/edge] edge (Cloudflare/Bunny/Varnish) config; edge owner |
| `class-edge-purger.php` | `Edge/` | [sysmap:Delivery/edge] edge purge fan-out; edge-purge owner |
| `class-cloudflare-purger.php` | `Edge/` | [sysmap:Delivery/edge] Cloudflare purge path; edge-purge owner |
| `class-server-rules.php` | `Edge/` | [sysmap:Delivery/edge] Nginx rules + server detection; [load] eager in `Main::includes()`; delivery owner |
| `class-htaccess-handler.php` | `Edge/` | [sysmap:Delivery/edge] Apache `.htaccess` rules; delivery owner |
| `class-header-emitter.php` | `Edge/` | [sysmap:Delivery/edge] header protocol (X-LiteSpeed-* + edge headers); [load] eager in `Main::includes()` |
| `class-litespeed-integration.php` | `Integrations/` | [sysmap:Delivery/edge] LiteSpeed coexistence modes; [load] eager + `should_load_litespeed_stack()` gate; protected owner (WONTFIX #1291) |
| `class-litespeed-crawler.php` | `Integrations/` | [sysmap:Delivery/edge] curl_multi preloader; [load] conditional eager (LiteSpeed stack); protected companion |
| `class-litespeed-esi.php` | `Integrations/` | [sysmap:Delivery/edge] ESI bridge (LSWS Enterprise; OLS disabled); [load] conditional eager; protected (behavior + load order frozen) |
| `class-woo-detect.php` | `Integrations/` | [sysmap:Cache-domain/Woo_Detect] Phase-1 boundary (REF-013); third-party detection integration |
| `class-builder-purge-watcher.php` | `Integrations/` | [sysmap:Lifecycle] builder (Elementor etc.) purge watcher; [load] eager in `Main::includes()`; third-party integration |
| `class-rest.php` | `Admin/` | [sysmap:Surface] 40-route registrar; [hotspot] ARCH-011 route-group split source; [dir] Presentation |
| `class-abilities.php` | `Admin/` | [sysmap:Surface] `Abilities` API surface; [dir] Presentation |
| `class-metabox.php` | `Admin/` | [sysmap:Surface] per-page preload + Asset Manager metabox; [dir] Presentation |
| `class-admin-notices.php` | `Admin/` | Admin-surface notices (missing-deps fail-open notice per [load] bootstrap); [dir] Presentation |
| `class-perf-translations.php` | `Admin/` | [sysmap:Lifecycle] SPA translation provisioning (`wppoSettings.translations`); [load] eager in `Main::includes()`; admin-surface owner |
| `class-wppo-cli-command.php` | `Admin/` | [sysmap:Surface] `wp wppo` CLI (7 subcommands); [load] `WP_CLI`-only require; [dir] Presentation |
| `class-core-tweaks.php` | `Compatibility/` | [sysmap:Lifecycle/Core_Tweaks] emoji/embeds/dashicons/XML-RPC/Heartbeat; core-behavior compat |
| `class-llms.php` | `Compatibility/` | [sysmap:Lifecycle/Llms] `/llms.txt` virtual files; [load] eager in `Main::includes()`; compat surface |
| `class-util.php` | `includes/` root (stay) | Compat facade over Phase-1 boundaries (REF-001..004, REF-013..015); stays until ARCH-014 migrates callers — see open points |
| `minify/` wrappers | `includes/minify/` (as-is) | Out of scope — see open points |

### Open points (resolved from evidence, recorded here)

1. `class-util.php` stays at `includes/` root until ARCH-014. It is the
   compatibility facade (33+ proxies over `Cache_Key`, `Settings_Store`,
   `Filesystem`, `Url`, `Woo_Detect`, `Scheduler`, `Http`) with the densest
   `Util::` edge fan-in in `DEPENDENCY-GRAPH.json`. Moving it early would
   churn every caller without benefit; ARCH-014 migrates in-domain callers
   to owning boundaries first, then the facade itself can move.
2. `redis-connect-helper.php` → `Support/`. Loaders confirmed by evidence
   sweep (all hardcode the flat `includes/` path today and move together in
   ARCH-013 with the ARCH-003 loader map):
   - `includes/class-object-cache.php:1594-1598` (`ensure_redis_helper()`
     requires `WPPO_PLUGIN_PATH . 'includes/redis-connect-helper.php'`;
     also `wppo_parse_nodes` / `wppo_resolve_redis_serializer` lazy ensures).
   - `templates/object-cache.php:215-231` (drop-in loads
     `<plugins_dir>/performance-optimisation/includes/redis-connect-helper.php`
     with `glob( $plugins_dir . '/*/includes/redis-connect-helper.php' )`
     fallback; core loads the drop-in before plugins).
   - Tests: `tests/php/PhpDeprecationHygieneTest.php:127,140,160`
     (`require_once ... '/includes/redis-connect-helper.php'`),
     `tests/php/ObjectCacheRedisResilienceTest.php:151`
     (`WPPO_PLUGIN_PATH . 'includes/redis-connect-helper.php'`),
     `tests/php/ObjectCacheTest.php:485` (helper-path derivation),
     `tests/php/ComplianceAuditTest.php:122` (path expectation).
   - Inventory: `scripts/generate-class-inventory.php:106`
     (`glob( $includes . '/redis-connect-helper.php' )`) — the ARCH-013 move
     must update the generator glob alongside the loader map.
3. `minify/` wrappers (`class-css.php`, `class-html.php`, `class-js.php`)
   keep as-is. They are third-party-adjacent wrappers outside the 57-file
   class inventory scope; no evidence demands moving them.
4. Flat rule: one level only (`includes/<Domain>/...`). No file's dependency
   evidence demands deeper nesting — even the largest hubs (`Main` 232
   methods, `Cache` 137, `Image_Optimisation` 165, `AI_Adaptive` 98) split
   along sibling-service lines (ARCH-004..011), not sub-trees. Prefer
   `includes/Cache/...` over deep trees.
5. Protected classes keep behavior + load order by construction: nothing
   moves in ARCH-002 (docs-only). `LiteSpeed_ESI` bridge + coexistence modes
   + header protocol (WONTFIX #1291), manual `Main::includes()` loading (no
   PSR-4), `advanced-cache.php` drop-in contract, Redis drop-in key
   namespacing, and uninstall behavior are unaffected; ARCH-013 moves must
   re-clear the move-safety constraints above.

## Generator limitations (accepted)

- Refs count static `ClassName::` occurrences only; `class_exists('...')` /
  `method_exists()` string guards (common in `Used_CSS`, `Rest`) are invisible.
- Method counts use leading-visibility `function` lines (matches campaign baseline).
- Hook lists capture literal `add_action`/`add_filter('name')` calls only.
- Regenerate: `php scripts/generate-class-inventory.php`; drift gate:
  `php scripts/generate-class-inventory.php --check`.
