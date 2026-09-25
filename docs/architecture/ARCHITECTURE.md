# Architecture: Performance Optimisation Plugin

Phase 3 evidence baseline: `ARCHITECTURE-BASELINE.md`

Quality model: `ARCHITECTURE-QUALITY.md`

Tokenizer graph: `DEPENDENCY-GRAPH.json`
Class heat map: `class-inventory.json`

Run `php scripts/generate-class-inventory.php` after runtime source changes. Run the command with `--check` to prove committed artifacts match the source.

## System map

```text
performance-optimisation.php
  ├─ Composer autoload and stale-classmap fallback
  ├─ Loader_Map
  └─ Main
      ├─ Core and lifecycle
      │   Hook_Registry, Preload_Buffer_Coordinator, Wp_Version, Activate, Deactivate
      ├─ Settings and scheduler
      │   Settings_Store, Settings_Migrations, Sandbox_Preview,
      │   Scheduler, Cron
      ├─ Shared boundaries
      │   Filesystem, Url, Http, Cache_Key, Woo_Detect, Log, Purge_Logger
      ├─ Cache and edge delivery
      │   Cache, Cache_Invalidator, Advanced_Cache_Handler, Bfcache,
      │   Object_Cache, Dropin_Registry, Redis_Config_Policy, CDN, CDN_Purger, Cloudflare_Purger, Edge_Cache,
      │   Edge_Purge_Coordinator, Edge_Purger, Server_Rules, Htaccess_Handler, Header_Emitter,
      │   LiteSpeed_Integration, LiteSpeed_Crawler, LiteSpeed_ESI
      ├─ Assets, images, and CSS
      │   Asset_Manager, Script_Strategy, Css_Combine, Css_Safelist,
      │   Google_Fonts, Image_Optimisation, Lcp_Preload, Img_Converter,
      │   Critical_CSS, Ccss_Generator, Ccss_Store, Used_CSS
      ├─ Database and insight
      │   Database_Cleanup, Database_Cleanup_Runner, Telemetry, Pagespeed, Insight_Query,
      │   Suggestion_Engine, System_Info, RUM, AI_Adaptive, Ai_Anomaly, OD_Bridge
      ├─ Admin and compatibility surfaces
      │   Rest, Rest_Cache, Rest_Settings, Abilities, Admin_Auth, Metabox,
      │   Admin_Notices, Perf_Translations, WPPO_CLI_Command,
      │   Core_Tweaks, Llms, Builder_Purge_Watcher
      └─ React admin SPA
          App, Dashboard, FileOptimization, preload, image, database,
          object-cache, plugin settings, audit, and system-info components
```

`includes/minify/` contains three protected vendor-adjacent wrappers. `templates/object-cache.php` defines the Redis drop-in class. `Util` remains at the `includes/` root as a compatibility and shared-policy layer.

## Current graph

The schema-v2 tokenizer graph covers 89 files: 85 class-like nodes and 4 procedural nodes.

| Signal | Current |
|---|---:|
| Unique edges | 395 |
| Runtime / compatibility / loader edges | 394 / 206 / 3 |
| Cross-domain / feature-to-feature edges | 330 / 50 |
| Boundary violations | 20 |
| Bridge candidates | 247 |
| Runtime SCCs | 1 |
| Largest runtime SCC | 68 nodes |
| Static state | 143 properties across 33 nodes |
| Exact duplicate candidates | 18 |

The runtime SCC shows reciprocal reach across major subsystems. It does not prove that one extraction will fix the whole component. Each queue item must identify a smaller owner and dependency path.

## Dependency direction

```text
Bootstrap / Core
        ↓
Application coordination
        ↓
Domain services
        ↓
Infrastructure and compatibility boundaries
        ↓
WordPress, filesystem, network, database, and external APIs
```

Current strict findings include:

- feature and presentation edges back to `Main`;
- `Util` edges to `CDN` and `Woo_Detect`;
- protected minify HTML reaching `Sandbox_Preview`;
- service-to-owner bridges that keep extracted classes coupled to `Main`.

Cross-domain edges require review. An edge can represent a real product interaction or shared policy. The graph labels it; reviewers assign the reason.

## Ownership map

| Owner | Current responsibility | Phase 3 direction |
|---|---|---|
| `Main` | Bootstrap, assets, speculation/resource hints, public compatibility facades, feature bridges | Keep construction, lifecycle, registration, and callback identity; move remaining independent policy |
| `Preload_Buffer_Coordinator` | Core template-enhancement routing, legacy used-CSS/LCP lifecycle, cache-aware scheduling seams | Keep dependency-light and bounded; do not absorb hook registration, speculation, image serving, or LiteSpeed lanes |
| `Util` | Compatibility proxies and residual canonical helpers | Give each method an owner, migrate callers, then thin or remove the facade |
| `Cache` | HTML cache policy, storage, invalidation facade, CSS-combine facade | Keep lifecycle and buffer orchestration; capacity/accounting is delegated to `Cache_Capacity` |
| `Cache_Capacity` | Static cache statistics, cap settings, single-walk byte/file accounting, randomized-query guard, oldest eviction | Keep the accounting contract narrow; retain only the bridges required for Cache filesystem, containment, and deletion policy |
| `Settings_Store` | Settings memo, validation map, snapshots, write/invalidation | Make it the only settings write owner; adapt REST, CLI, and Abilities |
| `Settings_Command` | Bounded settings write orchestration and snapshot/restore delegation | Keep callers on this seam; leave validation, memo, and canonical persistence in `Settings_Store` |
| `Scheduler` / `Job_Registry` | Action Scheduler primitives, locks, and owned hook manifest | Keep registry-backed scheduling and teardown; no duplicate hook lists |
| `Preload_Transport` | Same-host URL validation and bounded non-following redirects for Cron warmup | Keep all three Cron fetch seams on the transport policy; preserve LiteSpeed bypass |
| `Runtime_State` | Central switch_blog reset registry for six site-sensitive static-state owners | Keep reset methods feature-owned; classify the remaining static owners |
| `Redis_Config_Policy` | Complete Redis key manifest and value normalization | Keep REST and CLI on this narrow security policy; leave connection and persistence in `Object_Cache` |
| `Dropin_Registry` | Neutral forwarding seam for post-mutation drop-in cache invalidation | Keep fail-open and stateless; leave reporting, path/ownership detection, and storage in `System_Info` |
| `Object_Cache` | Redis backend, connection, circuit state, drop-in management | Keep lifecycle and drop-in behavior; expose the policy key constant for compatibility |
| `Critical_CSS` / `Ccss_Generator` / `Ccss_Store` | Fetch/parse/output orchestration, generation lifecycle status/retry policy, and file/status projection | Keep the three axes explicit; do not absorb frontend output, purge, or image/preload concerns |
| `Used_CSS` | Generation, storage, parsing, delivery, rollout | Split storage and generation only after Used CSS owns no cache purge policy |
| `Image_Optimisation` | Image markup, lazy loading, media transforms, conversion bridge | Extract cohesive media/metadata or conversion-state clusters with parity tests |
| `Insight_Query` | Cached telemetry/PageSpeed read models and PageSpeed suggestion projection | Keep read-only; leave scans, storage, AI, and RUM side effects with domain owners |
| `Database_Cleanup_Runner` | Canonical adapter dispatch, legacy CLI aliases, dry-run previews, activity logging/hooks, and post-cleanup optimization decisions | Keep REST/Abilities/CLI authorization, confirmation, output, and response envelopes outside the runner; keep SQL and counts in `Database_Cleanup` |
| `Admin_Auth` | Administrative capability and `wp_rest` nonce policy | Keep dependency-light and shared by REST/Abilities; public RUM validation stays in `RUM` |
| `Rest` / CLI / Abilities | Transport and route/command registration | Add narrow application commands and response mappers; keep transport thin |
| React cards | Local presentation and workflow state | Extract one card or hook at a time; keep `useState` and `wppoSettings` |

## High-value findings

1. **Preload transport:** Cron warmup now shares one bounded, same-host redirect policy; future fetch callers must use the owner rather than implicit redirects.
2. **Runtime state:** 33 owners hold 143 static properties. P3-004 resets six site-sensitive owners centrally; P3-021 selects the non-site-sensitive `Bfcache` request state for a shutdown reset and classifies persisted, compatibility, and protected residues.
3. **`Util` hub:** 60 source nodes and 1,084 executable occurrences still depend on it; P3-017 removed the Woo proxy cluster without removing the public facade.
4. **`Main` hub:** 38 outgoing class dependencies and 19 feature dependencies remain; P3-013 through P3-015 reduced large methods and moved bounded owners behind named coordinators, while Main retains public callback/facade identity.
5. **Cache capacity:** `Cache_Capacity` now owns the statistics, cap, and eviction contract; `Cache` remains the public facade and lifecycle owner.
6. **Settings writes:** `Settings_Command` now routes REST partial/import/safe-mode writes through `Settings_Store`; no direct runtime `update_option('wppo_settings')` remains outside the canonical store.
7. **Redis policy:** `Redis_Config_Policy` now owns the full key manifest and value sanitizer used by REST and CLI; `Object_Cache::ALLOWED_KEYS` remains a compatibility alias.
8. **Drop-in invalidation:** Advanced/Object cache mutators now invalidate through `Dropin_Registry`; `System_Info` remains the sole reporting and cache-storage owner.
9. **CSS and image cycles:** `Ccss_Generator` now owns generation lifecycle status/retry policy and `Ccss_Store` owns file projection, but parser/frontend bridges still keep the larger SCC connected.
10. **REST and CLI duplication:** `Admin_Auth` now owns the duplicated administrative capability/nonce decision; adapters still duplicate dispatch, settings, telemetry, and diagnostics.
11. **Database cleanup orchestration:** `Database_Cleanup_Runner` now owns the shared REST/Abilities/CLI dispatch, aliases, dry-run preview, and activity policy; adapters retain transport concerns.
12. **React async ownership:** P3-019 and P3-020 now centralize settings-response commits, save/abort/mount guards, and Dashboard image polling without a router, global store, or polling framework. FileOptimization card separation and Woo self-test transport remain bounded future work.

`ARCHITECTURE-BASELINE.md` records source evidence and queue priority for each finding.

## React constraints

The SPA keeps:

- `useState` only;
- conditional tab rendering without a router;
- `wppoSettings` as the server-provided snapshot;
- `apiCall()` for REST;
- `useNotice()` and `NoticeBanner` for shared feedback;
- committed `build/` output.

The React refactor sequence is one presentational card or one asynchronous hook per item. Tests must cover settings snapshots, deferred edits, aborts, cross-tab cache commits, and visible output.

## Loader constraints

The plugin keeps manual loading through `Main::includes()` and `Loader_Map`. Composer also generates a recursive plugin classmap, and `Loader_Map` covers stale or partial autoload states. The watchdog forbids a PSR-4 conversion.

Path moves update `Loader_Map`, Composer regeneration, tests, docs, and release verification in one change.

## Protected complexity

The following decisions remain fixed:

- LiteSpeed ESI bridge, coexistence modes, and `X-LiteSpeed-*` protocol;
- LiteSpeed crawler and conditional load behavior;
- `advanced-cache.php` early-load contract;
- Redis object-cache drop-in and blog key namespacing;
- WordPress multisite transient and option isolation;
- REST namespace, capability checks, and nonce policy;
- public `rum_collect` validation and rate limits;
- `useState`-only React architecture;
- manual class loading and no PSR-4 migration;
- committed build assets and release packaging.

## Required evidence

Architecture pull requests review:

- generated inventory and graph deltas;
- WPCS and test results;
- static-state classification;
- compatibility caller census;
- runtime verification after merge;
- queue and baseline updates.

A clean test suite or lower line count alone does not prove an architectural improvement.
