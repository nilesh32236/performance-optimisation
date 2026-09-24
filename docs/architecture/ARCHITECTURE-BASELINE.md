# Phase 3 Architecture Baseline

Captured: 2026-09-24 07:00 UTC
Source: `origin/master` commit `86e264b3b7f72b5f3f1a477fb26fc66217a57248`
Quality model: `ARCHITECTURE-QUALITY.md`

This document records the Phase 3 starting point. The generator produced every count from current PHP syntax. Manual review adds responsibility and runtime findings that a tokenizer cannot infer.

## Authoritative artifacts

Run:

```sh
php scripts/generate-class-inventory.php
php scripts/generate-class-inventory.php --check
```

The command writes:

- `class-inventory.json`, the class and protected-runtime heat map.
- `DEPENDENCY-GRAPH.json`, the schema-v2 tokenizer graph, SCCs, boundary findings, and duplicate candidates.

The CI workflow runs the check command. `ArchitectureInventoryTest` checks the schema, file-set completeness, required reference categories, graph classifications, and drift.

## Scope

The tokenizer scans 84 first-party runtime files:

| Scope | Files | Inventory treatment |
|---|---:|---|
| Plugin classes and traits under `includes/` | 76 | Runtime inventory and loader coverage |
| Redis procedural helper | 1 | Procedural inventory entry |
| Protected minify wrappers | 3 | `protected_vendor_adjacent` scope |
| Redis object-cache drop-in | 1 | `drop_in` scope |
| Plugin entry, uninstall, translation template | 3 | Procedural graph nodes |

The graph excludes `build`, `docs`, `node_modules`, `scripts`, `tests`, and `vendor`. It includes the plugin entry, `uninstall.php`, runtime templates, and the three minify wrappers so the graph does not hide drop-in or protected coupling.

## System totals

| Metric | Baseline |
|---|---:|
| Inventory files | 81 |
| Inventory source lines | 126,527 |
| Class-like graph nodes | 80 |
| Procedural graph nodes | 4 |
| Named methods | 2,511 |
| Methods spanning 80 lines or more | 236 |
| Static properties | 141 across 32 nodes |
| Unique dependency edges | 368 |
| Runtime-classified edges | 367 |
| Compatibility-classified edges | 195 |
| Loader-classified edges | 3 |
| Cross-domain edges | 313 |
| Feature-to-feature edges | 46 |
| Strict boundary violations | 16 |
| Bridge candidates | 231 |
| Exact-shape duplicate groups | 17 |
| Multi-node runtime SCCs | 1 |

Classifications can overlap on one edge. A guarded call can have both runtime and compatibility evidence.

## Dependency graph

The graph exposes one runtime strongly connected component with 63 class-like nodes and 317 runtime-classified internal edges. One compatibility-only SCC covers 21 nodes. P3-007 removed the separate compatibility-only System Info/drop-in pair. This is the campaign's central coupling finding. `Main`, `Util`, cache, CSS, images, insight, admin surfaces, and integration adapters can reach one another through executable references.

The largest hub scores are:

| Rank | Node | Score | Fan-in | Fan-out | Compatibility fan-in | Cross-domain |
|---:|---|---:|---:|---:|---:|---:|
| 1 | `Util` | 216.01 | 57 | 9 | 39 | 66 |
| 2 | `Main` | 120.09 | 15 | 34 | 8 | 43 |
| 3 | `Log` | 83.25 | 27 | 1 | 14 | 26 |
| 4 | `LiteSpeed_Integration` | 79.80 | 17 | 7 | 16 | 20 |
| 5 | `Cache` | 78.08 | 15 | 14 | 9 | 25 |
| 6 | `Used_CSS` | 52.96 | 10 | 8 | 6 | 17 |
| 7 | `Critical_CSS` | 52.95 | 9 | 8 | 7 | 14 |
| 8 | `RUM` | 50.35 | 14 | 2 | 9 | 12 |
| 9 | `Rest` | 51.74 | 1 | 24 | 0 | 23 |
| 10 | `Cron` | 46.79 | 5 | 16 | 3 | 18 |

The score formula lives in the graph metadata. It ranks review pressure; it does not grade code quality.

## Class heat map

`Evidence in` counts executable source occurrences behind incoming plugin edges. `Feature deps` counts unique domain-feature targets.

| Class | Lines | Methods | 80+ | Static | Private state | Fan in/out | Evidence in | Feature deps | Largest method |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---|
| `Main` | 11,091 | 235 | 21 | 12 | 38 | 15/34 | 131 | 17 | `get_options` 300 |
| `Image_Optimisation` | 8,999 | 183 | 13 | 7 | 30 | 4/4 | 11 | 3 | `add_delay_load_img` 476 |
| `Critical_CSS` | 6,950 | 138 | 12 | 11 | 11 | 9/8 | 50 | 5 | `generate` 267 |
| `Cache` | 5,643 | 161 | 8 | 4 | 29 | 14/13 | 71 | 8 | `maybe_store_cache` 177 |
| `Util` | 5,006 | 156 | 6 | 4 | 4 | 56/9 | 1,145 | 3 | `get_default_settings` 218 |
| `Used_CSS` | 4,961 | 94 | 8 | 1 | 11 | 10/8 | 39 | 5 | `regenerate_all` 291 |
| `Lcp_Preload` | 4,434 | 74 | 8 | 4 | 6 | 1/4 | 12 | 3 | `apply_occlusion_fetchpriority_low` 163 |
| `Img_Converter` | 4,077 | 66 | 8 | 4 | 8 | 9/2 | 36 | 0 | `convert_image` 691 |
| `Script_Strategy` | 3,859 | 56 | 12 | 11 | 12 | 1/4 | 35 | 1 | `add_defer_attribute` 203 |
| `AI_Adaptive` | 3,989 | 76 | 8 | 2 | 2 | 5/5 | 20 | 4 | `get_suggestions` 761 |
| `Rest` | 3,656 | 76 | 8 | 0 | 3 | 1/26 | 1 | 19 | `optimise_image` 310 |
| `Database_Cleanup` | 3,365 | 59 | 9 | 3 | 3 | 6/2 | 64 | 0 | `get_action_scheduler_health` 220 |
| `Database_Cleanup_Runner` | 323 | 7 | 0 | 0 | 0 | 3/2 | 6 | 1 | `run` 68 |
| `RUM` | 3,348 | 63 | 7 | 7 | 7 | 14/2 | 98 | 1 | `flush_queue` 328 |
| `Object_Cache` | 3,215 | 55 | 11 | 6 | 11 | 8/5 | 33 | 3 | `trip_circuit_on_outage` 174 |
| `Dropin_Registry` | 45 | 1 | 0 | 0 | 0 | 2/1 | 12 | 1 | `invalidate` 7 |
| `Redis_Config_Policy` | 183 | 3 | 0 | 0 | 0 | 3/0 | 6 | 0 | `sanitize_value` 58 |
| `LiteSpeed_Integration` | 2,797 | 58 | 4 | 17 | 17 | 17/7 | 95 | 5 | `get_litespeed_ttl` 216 |
| `WPPO_CLI_Command` | 2,362 | 27 | 7 | 0 | 0 | 1/14 | 2 | 11 | `settings` 224 |
| `Builder_Purge_Watcher` | 2,110 | 45 | 2 | 7 | 7 | 4/6 | 19 | 3 | `on_any_upgrade` 103 |
| `Cron` | 1,792 | 37 | 2 | 0 | 0 | 5/16 | 14 | 10 | `schedule_page_cron_jobs` 159 |
| `Scheduler` | 636 | 16 | 0 | 2 | 2 | 2/1 | 29 | 0 | largest under 80 lines |

Protected classes also need visibility:

| Class | Scope | Lines | Methods | 80+ | Coupling note |
|---|---|---:|---:|---:|---|
| `Minify\HTML` | Protected vendor-adjacent | 1,704 | 26 | 3 | Calls `Main`, `Util`, and `Sandbox_Preview`; shares exact-shape candidates with minify CSS/JS. |
| `Minify\CSS` | Protected vendor-adjacent | 403 | 6 | 2 | Calls `Util` and `Img_Converter`; no static state. |
| `Minify\JS` | Protected vendor-adjacent | 189 | 4 | 0 | Calls `Util`; no static state. |
| `WP_Object_Cache` | Drop-in | 1,821 | 30 | 2 | Redis adapter contract; no first-party class edges. |

## Reviewed responsibility clusters

These counts come from method names, call sites, tests, and history. They approximate independent reasons to change.

| Class | Approx. clusters | Independent axes found in source |
|---|---:|---|
| `Main` | 11 | Loader, options, lifecycle, asset enqueue, delay/defer, minification, URL/version policy, speculation, preload, cache coordination, admin/feature bridges |
| `Util` | 8 | Settings compatibility, filesystem compatibility, URL policy, preload helpers, MIME/HTML helpers, Woo compatibility, scheduler compatibility, teardown helpers |
| `Cache` | 8 | Output policy, storage, invalidation facade, CSS combine facade, statistics, capacity, randomized assets, integration bridges |
| `Image_Optimisation` | 8 | URL/metadata, next-gen formats, picture markup, lazy loading, video/media, dimensions, preload bridge, cache buffer integration |
| `Critical_CSS` | 7 | Generation, parsing, exclusions, budgets, rollout/status facade, purge/reactivity, store bridge |
| `Used_CSS` | 7 | Generation, storage, parsing, delivery, rollout, regeneration, purge integration |
| `Rest` | 8 | Registration/auth, cache/settings adapters, images, CSS, database, telemetry/insight, RUM, sandbox/preload |
| `WPPO_CLI_Command` | 9 | Eight command families plus shared CLI validation/formatting |
| `RUM` | 7 | Collection, validation, queue, aggregation, persistence, admin reads, lifecycle/reset |
| `Object_Cache` | 6 | Connection, reads/writes, flush, circuit state, drop-in management, diagnostics |
| `Dropin_Registry` | 1 | Neutral forwarding seam for post-mutation drop-in cache invalidation |
| `Redis_Config_Policy` | 1 | Redis key manifest and value normalization |
| `LiteSpeed_Integration` | 7 | Detection, coexistence, headers, TTL, purge, private/vary policy, server compatibility |
| `Cron` | 6 | Recurring scheduling, sitemap discovery, queue progress, URL/page fetch, image/preload/insight jobs, teardown |
| `Job_Registry` | 1 | Canonical WP-Cron/Action Scheduler hook ownership and teardown unions |
| `Preload_Transport` | 1 | Same-host target validation and bounded non-following redirect transport |
| `Runtime_State` | 1 | Six-owner blog-switch reset registry with feature-owned reset delegation |
| `Insight_Query` | 1 | Cached telemetry/PageSpeed read models and deterministic PageSpeed suggestion augmentation |
| `Admin_Auth` | 1 | Administrative capability, REST header canonicalization, legacy nonce fallback, and wp_rest verification |

## Coupling findings

### Util remains a dependency hub

The graph records 56 incoming source nodes and 1,145 incoming executable occurrences. `Main`, `Used_CSS`, `Critical_CSS`, `Cache`, and `Cron` account for most calls. The facade still contains canonical helpers, so callers must migrate by method ownership rather than replace every `Util::` call mechanically.

### Main still owns feature policy

`Main` has 34 outgoing class dependencies, 17 feature dependencies, 21 large methods, and direct service-to-owner bridges. Phase 1 and 2 moved several service/boundary clusters, but constructor state, asset policy, preload, speculation, minification, cache coordination, and feature bridges remain.

### Cache has a cohesive capacity tail

Before P3-005, `Cache` ended with an 839-line statistics, cap, and eviction cluster. `Cron`, `Rest_Cache`, `Main`, and CLI consumed it. `Cache_Capacity` was identified as the extraction owner because the cluster shared one accounting and retention contract.

P3-005 implementation update: `Cache_Capacity` now owns that statistics/cap/oldest-eviction contract. The generated inventory contains 75 files, 78 graph nodes, and 348 edges; `Cache` is 5,077 lines with 167 methods after facade bridges. Public callers remain on `Cache`, while the owner uses narrow filesystem/domain/containment/deletion bridges and preserves the one-walk, salted/stale stats, stampede lock, throttle, warning, and eviction semantics.

### CSS and image policy remains cross-owned

`Critical_CSS`, `Ccss_Store`, `Used_CSS`, and `Lcp_Preload` remain inside a 59-node runtime SCC with the wider plugin. Storage owners exist, but callers still route through facades and several policy helpers cross CSS and image boundaries.

### Scheduler ownership has a teardown hole

`Builder_Purge_Watcher` schedules `wppo_builder_drift_purge` and `wppo_upgrade_purge`. `Cron::AS_HOOKS`, deactivation, and uninstall do not own the full set. The upgrade hook can survive deactivation or uninstall. This direct lifecycle defect makes a scheduler job registry the first implementation item after P3-001.

### REST, CLI, and Abilities duplicate application decisions

The three adapters duplicate parts of permission checks, database dispatch, settings writes, telemetry reads, same-site URL validation, image paths, and response formatting. Later work should create narrow application services only where two or three adapters share the same policy.

### Redis configuration policy moved out of REST

P3-006 moves the complete Redis key manifest and value sanitizer from `Rest` into the dependency-light `Redis_Config_Policy`. REST and both CLI construction paths now call the same builder; `Object_Cache::ALLOWED_KEYS` remains as a public compatibility alias. The generated inventory contains 76 files, 79 graph nodes, and 351 edges. The policy has three public methods, no feature dependencies, and no connection, persistence, circuit, drop-in, flush, multisite, permission, or command-registration changes. Bridge candidates fell from 231 to 230 while the compatibility-edge count fell from 195 to 194.

P3-007 adds the dependency-light `Dropin_Registry` as the single neutral invalidation seam. `Advanced_Cache_Handler` routes its two create/remove invalidations through it, and `Object_Cache` routes all four enable/disable/circuit invalidations through it; all existing success conditions, loadability guards, and Throwable wrappers remain in place. `System_Info` remains the owner of drop-in reporting, path resolution, ownership detection, its request memo, transient deletion, and salted-cache salt bumps. The registry has one seven-line method, no state, two incoming feature edges, and one guarded outgoing bridge to `System_Info`. Direct Advanced/Object-to-System-Info invalidation edges fall from two to zero. The generated inventory now contains 77 files, 80 graph nodes, and 352 edges; feature-to-feature edges fall from 47 to 46, the compatibility-only SCC count falls from two to one, and bridge candidates remain 230.

P3-009 adds the 74-line, three-method `Insight_Query` read boundary. REST, Abilities, and CLI no longer read audit or PageSpeed result storage directly: `Telemetry` remains the salted/transient audit-cache owner, `Pagespeed` remains the result-key and pending/result reader, and `Suggestion_Engine` remains the deterministic PageSpeed projection. AI suggestion and RUM next-action calls remain at their adapter side-effect boundaries, while administrative `manage_options`/nonce gates and the public token/rate-limited RUM beacon remain unchanged. The generated inventory contains 79 files, 82 graph nodes, and 361 edges; direct audit-cache readers outside Telemetry fall from two to zero and feature-to-feature edges remain 46.

`Rest_Settings` and the REST safe-mode path still write `wppo_settings` outside `Settings_Store`; that remains queued for the later settings-command item.

P3-010 adds the 323-line, seven-method `Database_Cleanup_Runner` application boundary. REST, Abilities, and WP-CLI now delegate canonical validation, all/Action Scheduler dispatch, revision defaults, legacy CLI aliases, dry-run previews, logging/hooks, and REST table optimization through it; `Database_Cleanup` remains the SQL/map/counts/health owner, and Cron still calls `auto_clean()` directly. The generated inventory contains 80 files, 83 graph nodes, and 366 edges; the runner has three incoming feature edges and no static state.

P3-011 adds the 60-line, one-method dependency-light `Admin_Auth` policy. `Rest::permission_callback()` and `Abilities::permission_check()` retain their public signatures but delegate capability-first, request-header/legacy-server nonce handling, sanitization, and `wp_rest` verification to one owner. Public `rum_collect` remains `__return_true`, and RUM token, IP, and global rate-limit policy remains untouched. The generated inventory contains 81 files, 84 graph nodes, and 368 edges; `Admin_Auth` has two incoming edges, no feature dependencies, and no static state.

## Static state

The old regex found 12 stateful files. The tokenizer finds 141 static properties across 32 nodes. The largest owners are:

| Node | Static properties | Review finding |
|---|---:|---|
| `LiteSpeed_Integration` | 17 | Request, URI, purge, and setting memos lack complete blog scoping and central reset ownership. |
| `Main` | 12 | Request memos mix with persistent feature state; switch-blog coverage is partial. |
| `Script_Strategy` | 11 | Extracted delay/defer state still lives behind Main bridges. |
| `Critical_CSS` | 11 | Generation and status memos have separate reset paths. |
| `Image_Optimisation` | 7 | Media and preload state spans extracted and non-extracted owners. |
| `RUM` | 7 | Aggregate and queue memos need request, blog, and test reset contracts. |
| `Builder_Purge_Watcher` | 7 | Builder detection and purge state lacks one runtime reset owner. |
| `Object_Cache` | 6 | Circuit state and outage state use different scoping models. |

`Ai_Anomaly` provides the positive precedent: it keys request memos by blog ID and exposes a reset. The next static-state item should create a runtime reset registry, classify each memo, and add blog-switch parity before removing scattered test setup.

## Duplicate candidates

The tokenizer reports exact normalized token shapes, not semantic equivalence. Review found these high-value groups:

| Group | Nodes | Review |
|---|---|---|
| `DUP-6CEEF0BFC9BB` | `Critical_CSS`, `Used_CSS` | 129-token shared shape; inspect storage and regeneration policy before extraction. |
| `DUP-9DCE80404093` | minify CSS, minify JS | 271-token protected shared shape; do not merge protected wrappers without runtime proof. |
| `DUP-690DEECFA0EE`, `DUP-B59D40DC024A`, `DUP-126282831132` | `Critical_CSS`, `Used_CSS` | Multiple CSS parity shapes; likely shared policy or intentional generation differences. |
| `DUP-03D5DB85941E` | `Cache`, `Main` | 114-token cache/main shape; inspect only after ownership classification. |
| `DUP-2567C12020FF` | `Settings_Store`, `Util` | Thin settings compatibility shape; expected during facade migration. |
| `DUP-C8E23DF0CE55`, `DUP-42AF7FCA67B5`, `DUP-C04A890B17D8` | cache, main, used CSS | Small validation or purge shapes; semantic review decides whether to consolidate. |

The graph never treats a duplicate candidate as a defect by itself.

## React baseline

| File | Lines | Responsibility signal |
|---|---:|---|
| `FileOptimization.js` | 6,114 | Ten inline feature cards, async save/sandbox/regeneration flows, shared settings state |
| `Dashboard.js` | 2,168 | Polling shell, two large inline cards, several mount-fetching child panels |
| `App.js` | 654 | Tab shell, guard/menu state, global activities and CCSS fetches, render dispatch |
| `PluginSetting.js` | 1,513 | Import/export, snapshots, API key, activity tools |
| `ImageOptimization.js` | 1,423 | Image settings, formats, dimensions, conversion status |
| `ObjectCache.js` | 1,194 | Redis configuration, status, controls, destructive actions |
| `PreloadSettings.js` | 955 | Preload, sitemap, preconnect, font/CSS hints |
| `DatabaseCleanup.js` | 941 | Nine cleanup families and confirmation flow |
| `PerformanceAudit.js` | 847 | Audit polling and result presentation |
| `WelcomePanel.js` | 814 | Onboarding and Woo self-test lifecycle |
| `AutoloadedOptions.js` | 805 | Option-bloat audit and remediation |

The React audit found four bounded families:

1. Full settings responses from `sandbox_promote`, `apply_preset`, and `import_settings` do not all commit to the global `wppoSettings` cache.
2. Save flows can mark render-time settings clean after an await, which can clobber edits made during the request.
3. Abort and unmount paths can leave hydration or polling guards stuck.
4. Dashboard and child panels duplicate polling constants and self-test transport.

These findings belong in small bug-safe queue items. They do not justify a router, store, or polling framework.

## Loader and installed WordPress baseline

### Loader

- The plugin entry loads Composer, then falls back to `Loader_Map` and `Main` for stale classmaps.
- `Loader_Map` owns eager files, the LiteSpeed conditional list, the fallback map, and the CLI path.
- Composer uses a recursive plugin classmap plus vendor packages. `Loader_Map` remains the runtime safety net and the watchdog forbids a PSR-4 migration.
- PHPUnit requires the repository object-cache template, not the deployed drop-in.

### Installed site

| Fact | Value |
|---|---|
| WordPress | 7.1.2 |
| PHP CLI | 8.3.33 |
| Plugin | 2.4.0, active |
| Site mode | Single site |
| REST namespace | 48 registered patterns including the root, representing 47 concrete endpoints |
| WP-CLI | 8 `wp wppo` subcommands, including `verify` |
| `wp wppo verify` | 6/7 pass |
| Known warning | Cache root belongs to `nobody` and is not writable by the CLI user |

The installed `/wp-content/object-cache.php` still probes the pre-ARCH-013 flat Redis helper path. The repository template and `Object_Cache` loader use `includes/Support/redis-connect-helper.php`. The live drop-in logs `wppo_redis_connect() not found`; post-merge environment refresh must repair the deployed copy without touching unrelated site data.

The stored `WPPO_VERSION` option remains `2.3.0` while the loaded plugin reports `2.4.0`. Treat this as installed-state drift and verify update behavior before changing runtime code.

## Static-analysis baseline

PHPStan 2.2.9 and `phpstan.neon` exist. The current config scans `includes/` at level 5; the new `scripts/architecture/` tooling and architecture tests are covered by PHP syntax, PHPCS, and PHPUnit but are outside the configured PHPStan paths. A local 4 GB run reports 245 errors and one unmatched ignore pattern. Several errors expose real ownership or state issues, including an undefined variable and a static-property mismatch in `Script_Strategy` and `Image_Optimisation`. CI does not enforce PHPStan yet. A focused run of the two new architecture scripts reports no errors.

Phase 3 should record a reviewed PHPStan baseline after the first ownership extractions. It should not add broad ignores to make the current count disappear.

## Baseline decision

P3-001 establishes the measurement system. P3-002 will centralize Action Scheduler and WP-Cron job ownership, including builder drift and upgrade purge teardown. That item improves dependency direction, removes duplicate lifecycle lists, fixes a pending-job leak, and gives later scheduler migrations one canonical registry.

Util migration, settings writes, REST/CLI application boundaries, CSS and image decompositions, and React card extraction remain queued. Cache capacity, runtime-state reset ownership, Redis configuration policy, neutral drop-in invalidation, bounded settings writes, and the canonical insight read model are implemented in this phase.
