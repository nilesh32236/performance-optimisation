# Phase 3 Architecture Baseline

Captured: 2026-09-24 07:00 UTC
Source: `origin/master` commit `85ed4122ecb523b2d728c6f78af90361e87c2050`
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

The tokenizer scans 88 first-party runtime files:

| Scope | Files | Inventory treatment |
|---|---:|---|
| Plugin classes and traits under `includes/` | 79 | Runtime inventory and loader coverage |
| Redis procedural helper | 1 | Procedural inventory entry |
| Protected minify wrappers | 4 | `protected_vendor_adjacent` scope |
| Redis object-cache drop-in | 1 | `drop_in` scope |
| Plugin entry, uninstall, translation template | 3 | Procedural graph nodes |

The graph excludes `build`, `docs`, `node_modules`, `scripts`, `tests`, and `vendor`. It includes the plugin entry, `uninstall.php`, runtime templates, and the four minify wrappers/policy so the graph does not hide drop-in or protected coupling.

## System totals

| Metric | Baseline |
|---|---:|
| Inventory files | 85 |
| Inventory source lines | 127,012 |
| Class-like graph nodes | 84 |
| Procedural graph nodes | 4 |
| Named methods | 2,539 |
| Methods spanning 80 lines or more | 233 |
| Static properties | 143 across 32 nodes |
| Unique dependency edges | 383 |
| Runtime-classified edges | 382 |
| Compatibility-classified edges | 199 |
| Loader-classified edges | 3 |
| Cross-domain edges | 321 |
| Feature-to-feature edges | 46 |
| Strict boundary violations | 20 |
| Bridge candidates | 238 |
| Exact-shape duplicate groups | 17 |
| Multi-node runtime SCCs | 1 |

Classifications can overlap on one edge. A guarded call can have both runtime and compatibility evidence.

## Dependency graph

The graph exposes one runtime strongly connected component with 67 class-like nodes and 329 runtime-classified internal edges. One compatibility-only SCC covers 21 nodes. P3-007 removed the separate compatibility-only System Info/drop-in pair. This is the campaign's central coupling finding. `Main`, `Util`, cache, CSS, images, insight, admin surfaces, and integration adapters can reach one another through executable references.

The largest hub scores are:

| Rank | Node | Score | Fan-in | Fan-out | Compatibility fan-in | Cross-domain |
|---:|---|---:|---:|---:|---:|---:|
| 1 | `Util` | 216.01 | 57 | 9 | 39 | 66 |
| 2 | `Main` | 126.23 | 16 | 37 | 8 | 46 |
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
| `Main` | 10,225 | 236 | 17 | 12 | 39 | 16/37 | 133 | 18 | `enqueue_scripts` 203 |
| `Image_Optimisation` | 8,999 | 183 | 13 | 7 | 30 | 4/4 | 11 | 3 | `add_delay_load_img` 476 |
| `Critical_CSS` | 6,950 | 138 | 12 | 11 | 11 | 9/8 | 50 | 5 | `generate` 267 |
| `Cache` | 5,643 | 161 | 8 | 4 | 29 | 14/13 | 71 | 8 | `maybe_store_cache` 177 |
| `Util` | 4,791 | 156 | 5 | 4 | 4 | 57/9 | 1,138 | 3 | `get_with_stampede_lock` 197 |
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
| `Edge_Purge_Coordinator` | 150 | 4 | 0 | 0 | 0 | 1/2 | 1 | 0 | `purge_after_cache_clear` 27 |
| `Cache_Coordinator` | 56 | 1 | 0 | 0 | 0 | 1/1 | 1 | 0 | `create` 15 |
| `Preload_Buffer_Coordinator` | 406 | 11 | 0 | 0 | 1 | 2/3 | 2 | 1 | `queue_crawler_warm_after_cache_invalidation` 31 |
| `Settings_Store` | 1,666 | 25 | 3 | 4 | 2 | 6/0 | 37 | 0 | `sanitize_settings_recursively` 401 |
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
| `Edge_Purge_Coordinator` | 1 | One cache-clear fan-out and per-event identical Cloudflare transport de-duplication |
| `Cache_Coordinator` | 1 | Cache construction plus the unchanged injection-filter contract; Main retains the public facade |
| `Preload_Buffer_Coordinator` | 1 | WP 6.9 template-enhancement routing, legacy used-CSS/LCP lifecycle, and cache-aware scheduling seams |
| `Settings_Store` effective-read policy | 1 | Canonical defaults/stored replacement, historical in-memory backfills, and blog-keyed resolved memo invalidation |
| `Woo_Detect` | 1 | Canonical safe-mode, Store API, wc-ajax/add-to-cart, faceted-query, dynamic-path, URL-exclusion, and read-only self-test policy; external cache/preload/frontend callers use this owner while `Util` retains compatibility proxies |

## Coupling findings

### Util remains a dependency hub

The graph records 57 incoming source nodes and 1,138 incoming executable occurrences. `Main`, `Used_CSS`, `Critical_CSS`, `Cache`, and `Cron` account for most calls. The facade still contains canonical helpers, so callers must migrate by method ownership rather than replace every `Util::` call mechanically.

### Main still owns feature policy

`Main` has 37 outgoing class dependencies, 18 feature dependencies, 17 large methods, and direct service-to-owner bridges. P3-013 removed the options resolver and cache-construction policy; P3-014 moved bounded minification behind `Minify_Policy`; P3-015 moves the bounded preload/buffer lifecycle and cache-aware scheduling seams behind `Preload_Buffer_Coordinator`. The owner edges are explicit delegation contracts; speculation, resource hints, image serving, and unrelated feature policy remain on Main.

### Cache has a cohesive capacity tail

Before P3-005, `Cache` ended with an 839-line statistics, cap, and eviction cluster. `Cron`, `Rest_Cache`, `Main`, and CLI consumed it. `Cache_Capacity` was identified as the extraction owner because the cluster shared one accounting and retention contract.

P3-005 implementation update: `Cache_Capacity` now owns that statistics/cap/oldest-eviction contract. The generated inventory contains 75 files, 78 graph nodes, and 348 edges; `Cache` is 5,077 lines with 167 methods after facade bridges. Public callers remain on `Cache`, while the owner uses narrow filesystem/domain/containment/deletion bridges and preserves the one-walk, salted/stale stats, stampede lock, throttle, warning, and eviction semantics.

### CSS and image policy remains cross-owned

`Critical_CSS`, `Ccss_Generator`, `Ccss_Store`, `Used_CSS`, and `Lcp_Preload` remain inside the wider runtime SCC. P3-018 gives generation status/retry/queue lifecycle one owner and leaves fetch/parse/output plus frontend delivery on `Critical_CSS`; storage/status projection remains on `Ccss_Store`. Parser, image, and compatibility bridges still keep the SCC connected.

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

P3-012 adds the 150-line, four-method `Edge_Purge_Coordinator`. `Hook_Registry` now registers one priority-10 `wppo_after_cache_clear` listener that calls the unchanged `CDN_Purger` and `Edge_Purger` adapters in their historic order. For a full clear, temporary `pre_http_request` / `http_api_debug` seams reuse the first response only when an identical Cloudflare purge_everything request repeats in that event, reducing overlapping Cloudflare transport from up to two calls to one while retaining separate Bunny and Varnish paths. LiteSpeed sync, single-page Cloudflare files, edge locks, provider-specific failure logging, invalid-payload TypeError behavior, and successful no-data behavior remain with their existing owners. The generated inventory contains 82 files, 85 graph nodes, and 369 edges; the coordinator has one incoming and two outgoing feature edges, no static state, and no feature-to-feature change.

P3-013 moves the canonical defaults, effective-options policy, and all 180 historical backfill assignments from `Util`/`Main::get_options()` to `Settings_Store`. Raw and resolved options are memoized by blog ID; add/update/delete and command writes invalidate the resolved snapshot so same-request reads reapply backfills without persisting them. `Util::get_default_settings()` remains its unchanged facade, while `Main` retains its public options facade, injectable local snapshot, Hook_Registry callback identities, and settings-update side effects. Main's one rollback write now routes through `Settings_Command`, so Main owns no direct `wppo_settings` write. The one bounded cache cluster is the 56-line, one-method `Cache_Coordinator`: `Main::create_cache()` remains the unchanged static facade while construction, collaborator injection, and `wppo_cache_instance` filtering move together. The generated inventory contains 83 files, 86 graph nodes, and 373 edges; Main drops from 11,091 to 10,790 lines and from 21 to 20 methods at least 80 lines, Util drops from 5,006 to 4,791 lines, the runtime SCC grows only from 64 to 65 nodes, and Main feature dependencies remain 17.

P3-014 adds the 385-line, eight-method `Minify_Policy` for the bounded Main minification cluster. `Main` retains public `minify_queued_styles`, `minify_css`, and `minify_js` facades plus callback identity and narrow state bridges; the policy owns queue detection, CSS/JS tag transformation, minified-name/file checks, randomized-query guard, containment, and fail-open behavior. Speculation/resource-hint policy remains outside this item. The generated inventory contains 84 files, 87 graph nodes, and 378 edges; Main's public minify cluster is reduced while feature-to-feature edges remain 46.

P3-015 adds the 406-line, eleven-method `Preload_Buffer_Coordinator` for one bounded coordination cluster. `Main` retains the used-CSS/LCP callback methods, static core-buffer predicate, post-save scheduling callbacks, Hook_Registry callback targets, cache (10) → used CSS (20) → LCP (30) enhancement order, and legacy priority-20 LCP start. The coordinator owns core availability routing, legacy buffer guards and one-shot used-CSS lifecycle, post-save crawler-warm and used-CSS enqueue gates, and fail-open mixed-version Woo URL checks. Speculation/resource hints, image serving, LiteSpeed crawler/ESI behavior, Redis/drop-ins, and data remain unchanged. Main drops from the branch baseline of 10,461 lines / 236 methods / 20 large methods to 10,225 / 236 / 17; the generated inventory contains 85 files, 88 graph nodes, and 383 edges. The explicit owner edge adds one bridge candidate and one Core-to-domain boundary finding; feature-to-feature edges remain 46.

P3-016 (issue #1597) removes the `Settings_Migrations` → `Main` owner-state bridge. The migration service now receives a callable effective-options reader and an explicit invalidation command, while `Main` retains all public migration proxies and Hook_Registry callback identities. `Settings_Store` remains the settings persistence and raw/resolved memo owner; migration ordering, per-site option reads, callbacks, data, REST, schemas, and multisite behavior are unchanged. The generated inventory remains 85 files, 88 graph nodes, and 383 edges, with the migration node no longer dependent on `Main`.

P3-017 (issue #1599) migrates one bounded external Woo caller cluster from `Util` compatibility proxies to `Woo_Detect`: advanced-cache generation, static-cache serve/storage guards, cache invalidation, sitemap preload and crawler-warm scheduling, frontend delay-JS decisions, and speculation exclusions. Safe-mode defaults and opt-out behavior, Store API and wc-ajax unconditional bypasses, faceted-query refusal, custom/nested dynamic paths, mixed-version regex fallbacks, fail-open catches, hook callback identities, cache/preload/frontend behavior, and per-site/multisite resolution are unchanged. REST, LiteSpeed, settings, and the remaining `Util` method clusters are untouched. All 11 public `Util` Woo proxies retain their signatures and delegate to `Woo_Detect`.

The generated inventory remains 85 files, 88 graph nodes, and 2,541 methods. Seven cache/Core/Assets/Scheduler callers now have explicit `Woo_Detect` edges, reducing incoming executable evidence on `Util` from 1,138 to 1,084. Because those callers still use unrelated `Util` settings/filesystem/URL/cache-key methods, `Util` fan-in remains 59; the direct owner edges temporarily raise total edges from 383 to 390, compatibility edges from 199 to 205, feature-to-feature edges from 46 to 50, and bridge candidates from 238 to 245. Those duplicate caller→`Util` edges disappear as the separately queued settings/filesystem/URL/cache-key/HTTP clusters migrate. The runtime SCC remains 67 nodes (336 edges); the compatibility SCC grows from 21 to 22 nodes (72 edges) because seven direct owner references join the existing `Woo_Detect` ↔ `Util` facade cycle.

P3-018 (issue #1602) selects one bounded Critical CSS lifecycle cluster from the fresh graph. New `Ccss_Generator` owns generation status values and keys, salted/transient persistence, bounded generic/timeout retry counters, failure/escalation logging, and the dedicated-plus-legacy Action Scheduler/WP-Cron liveness/enqueue policy. `Critical_CSS` keeps the public retry-cap and store status facades plus all public generation/frontend callbacks; `Ccss_Store` reads the canonical status reader directly and remains the file/staging/variant/status-projection owner. The moved private implementation methods have no compatibility proxies because they were not public or hook-visible. Critical_CSS falls from 6,950 lines / 138 methods / 11 static properties to 6,251 / 126 / 10; the new owner is 593 lines / 17 methods / one request memo. Total graph metrics are 86 inventory files, 89 graph nodes, and 395 edges; compatibility edges are 206, bridge candidates 247, and feature-to-feature edges remain 50. The explicit owner edges are documented bridges, and the added exact-shape group is a review signal rather than evidence of shared semantics.

## Static state

The old regex found 12 stateful files. The tokenizer finds 143 static properties across 33 nodes. The largest owners are:

| Node | Static properties | Review finding |
|---|---:|---|
| `LiteSpeed_Integration` | 17 | Request, URI, purge, and setting memos lack complete blog scoping and central reset ownership. |
| `Main` | 12 | Request memos mix with persistent feature state; switch-blog coverage is partial. |
| `Script_Strategy` | 11 | Extracted delay/defer state still lives behind Main bridges. |
| `Critical_CSS` | 10 | Generation/status queue state moved to `Ccss_Generator`; parser, frontend, commerce, gzip, and probe memos remain here. |
| `Image_Optimisation` | 7 | Media and preload state spans extracted and non-extracted owners. |
| `RUM` | 7 | Aggregate and queue memos need request, blog, and test reset contracts. |
| `Builder_Purge_Watcher` | 7 | Builder detection and purge state lacks one runtime reset owner. |
| `Object_Cache` | 6 | Circuit state and outage state use different scoping models. |
| `Settings_Store` | 4 | Raw and resolved settings memos are blog-keyed; add/update/delete/write paths invalidate resolved snapshots. |

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
| `Dashboard.js` | 2,011 | Polling shell, two large inline cards, several mount-fetching child panels; image-job polling delegates to `useImageJobPolling.js` |
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

1. Full settings responses from `sandbox_promote`, `apply_preset`, and `import_settings` did not all commit to the global `wppoSettings` cache. P3-019 now routes all settings-bearing responses through `settingsResponse.js`; full maps and nested preset settings are committed centrally, with empty/degraded payloads rejected fail-safe.
2. Save flows could mark render-time settings clean after an await, clobbering edits made during the request. P3-019 adds server-payload-first saves and a shared sequence/abort/mounted workflow guard.
3. Abort and unmount paths could leave hydration or polling guards stuck. P3-019 makes sandbox, used-CSS, CCSS, import, preset, and save async completions explicitly abort/stale/mount guarded.
4. Dashboard and child panels duplicate polling constants and self-test transport. P3-020 now isolates Dashboard's `image_job_status` polling boundary in `src/lib/useImageJobPolling.js`, reusing P3-019 `useAsyncWorkflow` for cancellation, stale-response rejection, and unmount cleanup. The hook preserves the existing 5s/60-attempt backoff, queued-job/savings projection, notices, action/auth/response contracts, and no-overlap scheduling. Woo self-test transport consolidation and FileOptimization card separation remain deferred follow-up boundaries.

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
