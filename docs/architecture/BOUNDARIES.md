# Architecture Boundaries: Performance Optimisation Plugin

Phase 3 evidence: `ARCHITECTURE-BASELINE.md` and `DEPENDENCY-GRAPH.json`

Quality rules: `ARCHITECTURE-QUALITY.md`

A boundary owns one decision or one external capability. Extraction does not create ownership by itself; the target class must own the policy, mutable state, and tests.

## Layer model

| Layer | Current owners | Allowed direction |
|---|---|---|
| Bootstrap and Core | Plugin entry, `Loader_Map`, `Main`, `Hook_Registry`, `Wp_Version`, activation/deactivation | Calls application, domain, and infrastructure contracts |
| Application coordination | `Scheduler`, `Cron`, `Settings_Migrations`, `Sandbox_Preview`, `Builder_Purge_Watcher` | Calls domain services and infrastructure; avoids presentation |
| Domain | Assets, Cache, CSS, Database, Edge, Images, Insight, Integrations | Calls infrastructure and shared policy; avoids `Main`, Admin, and other feature internals |
| Presentation | Rest, Abilities, Metabox, admin notices, WP-CLI, React | Calls application/domain contracts; owns transport and display only |
| Infrastructure and compatibility | Support, Settings_Store, Cache_Key, `Util`, protected minify wrappers, drop-ins | Provides narrow shared services; avoids feature internals |
| Lifecycle and integrations | Activate, Deactivate, uninstall, LiteSpeed stack, Woo detection | Adapts WordPress and external runtime contracts without becoming feature policy |

The graph reports 16 strict violations against this model, plus compatibility-only cycles. A violation can be compatibility debt, protected complexity, or misplaced policy. Each executable queue issue must name which category it addresses; planning buckets are split before issue creation.

## Shared boundaries

| Boundary | Owns | Must not own | Phase 3 evidence |
|---|---|---|---|
| `Cache_Key` | Transient, option, cache-salt, and stampede key policy | Storage, TTL, feature settings | Classified as infrastructure despite its `Cache/` path |
| `Settings_Store` | Settings read/write, validation dispatch, snapshots, memo reset | REST/CLI response shaping, feature defaults outside the schema | REST still writes `wppo_settings` through two direct paths |
| `Filesystem` | WP_Filesystem/native I/O, atomic writes, containment, path helpers | Cache policy, HTML mutation | Four static properties; partial test reset ownership |
| `Url` | Home/content URL memos, normalization, same-site policy, redirect parsing | Settings arrays, feature decisions | Five static properties; preload policy needs a separate allowance-preserving owner |
| `Scheduler` | Action Scheduler and WP-Cron primitives, locks, job ownership registry | Job payload policy owned by a feature | First Phase 3 implementation target; teardown lists miss builder hooks |
| `Http` | Shared cURL, finfo, XML, GD teardown | Provider request semantics | Small, stable support boundary |
| `Woo_Detect` | Woo detection and exclusion policy | Cache invalidation actions | `Util` still calls it, keeping a shared-facade edge |
| `Log` | Activity persistence and logging calls | Feature decisions | Fan-in 27; deliberate infrastructure hub, not a god class |
| `Loader_Map` | Eager files, fallback map, CLI path | Feature behavior | Three loader edges; stale-classmap safety remains required |
| `Util` | Residual canonical helpers and backward-compatible proxies | New feature policy or a new dependency hub | Fan-in 56 and 1,145 incoming executable occurrences |

## Domain boundaries

### Cache

| Owner | Current scope | Next ownership move |
|---|---|---|
| `Cache` | HTML cache policy, output buffer, storage, invalidation and CSS-combine facades | Keep lifecycle and buffer orchestration; extract capacity/accounting |
| `Cache_Invalidator` | Invalidation, purge fallback, deletes | Keep; remove temporary owner bridges when callers permit |
| `Cache_Capacity` | Static cache statistics, cap settings, byte/file accounting, randomized-query guard, oldest eviction | Keep narrow owner bridges for Cache filesystem, containment, and sibling-aware deletion |
| `Cache_Key` | Key derivation | Keep as infrastructure |
| `Advanced_Cache_Handler` | `advanced-cache.php` drop-in lifecycle | Protect early-load contract |
| `Redis_Config_Policy` | Complete Redis key manifest, host/node safety, value bounds and enums, password precedence | Keep dependency-light; do not connect, persist, flush, or publish drop-ins |
| `Object_Cache` | Redis connection, reads/writes, circuit, drop-in management, config key compatibility constant | Keep lifecycle and storage behavior; route adapter construction through `Redis_Config_Policy` |
| `Bfcache` | Logged-in no-store policy | Keep narrow |

The 839-line `Cache` tail previously owned statistics, cap settings, capacity checks, and eviction. `Cache_Capacity` now owns that one accounting contract; `Cache` retains only the public facade and lifecycle/storage policy. The owner reaches Cache through narrow filesystem, root/domain, containment, and deletion bridges.

### Images

| Owner | Current scope | Next ownership move |
|---|---|---|
| `Image_Optimisation` | Image metadata, next-gen markup, picture, lazy loading, media transforms, preload facade | Extract cohesive media/metadata or conversion-state clusters |
| `Lcp_Preload` | LCP resolution, preload emission, dedup state | Keep; callers may migrate from the old facade |
| `Img_Converter` | WebP/AVIF conversion and conversion metadata | Consider an `Img_Info_Store` only after atomic and shutdown semantics are pinned |

Do not split `Image_Optimisation` by arbitrary file size. Test output markup, MIME negotiation, dimensions, lazy loading, builder compatibility, and cache-buffer integration.

### CSS

| Owner | Current scope | Next ownership move |
|---|---|---|
| `Critical_CSS` | Generation, parsing, exclusions, budgets, rollout facade, purge/reactivity | Move remaining generation/parsing clusters after storage stays in `Ccss_Store` |
| `Ccss_Store` | Files, staging, promotion, variants, status | Keep as the storage/status owner |
| `Used_CSS` | Generation, storage, parsing, delivery, rollout, purge integration | Split storage only after it stops owning coupled purge policy |
| `Css_Combine` | Combined CSS fetch, minify, write, inline budget | Keep in Assets; remove Cache owner bridges when safe |
| `Css_Safelist` | Shared CSS exclusions | Keep as a small policy boundary |

`Critical_CSS` and `Used_CSS` must not become reciprocal policy stores. Shared checksum, sanitization, and exclusion rules need one named owner.

### Insight and telemetry

| Owner | Current scope | Next ownership move |
|---|---|---|
| `Telemetry` | Local performance scan and salted audit cache | Expose one read/invalidate contract for REST, Abilities, and CLI |
| `Pagespeed` | PageSpeed transport, scan jobs, trends, LCP storage | Keep transport separate from analysis |
| `RUM` | Collection, queue, aggregation, persistence, admin reads | Split blog-scoped state and admin projection |
| `AI_Adaptive` | Suggestion, model persistence/learning, speculation autotune | Keep model writes behind an explicit owner; move reaction side effects out of anomaly code |
| `Ai_Anomaly` | Math, thresholds, breach state, RUM digest | Keep; use its blog-aware memo pattern as the reset precedent |
| `Suggestion_Engine` | Cross-source recommendations | Call canonical telemetry/PageSpeed/RUM contracts |
| `System_Info` | Environment and infrastructure facts | Remove its cycle with `Object_Cache` report/drop-in ownership |

Fetch, persist, analyze, schedule, and display should not share one new “Service” class. Create narrow use-case owners only when the graph shows repeated calls or misplaced writes.

### Database

`Database_Cleanup` owns the cleanup method map and SQL behavior. REST already calls that map. The remaining duplication sits in adapter orchestration: validation, counts, Action Scheduler availability, logging, table optimization, and response shape. A later `Database_Cleanup_Runner` can serve REST, Abilities, and CLI without changing the canonical map.

### Edge and integrations

- `Edge_Cache` owns edge-provider configuration.
- `Edge_Purger` and `CDN_Purger` own purge fan-out.
- `Cloudflare_Purger` owns Cloudflare transport.
- `LiteSpeed_Integration` owns coexistence, headers, TTL, and purge coordination.
- `LiteSpeed_Crawler` owns crawl scheduling and variants.
- `LiteSpeed_ESI` stays protected.

The current hook registry and purger fallback can send one Cloudflare purge through more than one path. A later queue item must prove duplicate side effects before consolidating dispatch.

## Presentation boundaries

### REST

`Rest` still owns route registration, authorization helpers, response envelopes, throttling, and many domain handlers. `Rest_Cache` and `Rest_Settings` own two handler groups, but current route callbacks point directly to service instances. Their owner bridges support compatibility and tests.

The next REST step should extract a narrow application command or response mapper. It should not redo Phase 2 route groups.

### WP-CLI

`WPPO_CLI_Command` owns eight subcommands, including `verify`. It also contains filesystem, drop-in, LiteSpeed, cron, and system diagnostics. Redis argument and stored-settings construction delegates to `Redis_Config_Policy`. Treat it as a transport and diagnostics surface, not a thin adapter.

### Abilities

`Abilities` duplicates parts of REST permission checks, telemetry reads, URL validation, image operations, and database orchestration. Shared application services must preserve the Abilities API registry and permission model.

### React

The SPA keeps `useState`, `wppoSettings`, and `apiCall`. Boundaries include:

- presentational cards;
- one hook per async workflow;
- pure settings-response mappers;
- shared notice feedback;
- local polling and dirty-state ownership.

Current sizes are `FileOptimization.js` 6,114 lines, `Dashboard.js` 2,168, and `App.js` 654. One card or one hook per item keeps regressions searchable.

## Configuration ownership

A feature should not read another feature's internal settings paths. `Settings_Store` owns schema validation and writes. Feature owners should expose typed or narrow validated access for their own settings.

Two high-value corrections:

1. Move `Rest_Settings` and REST safe-mode direct writes behind `Settings_Store`.
2. Keep Redis config construction on `Redis_Config_Policy`; REST, CLI, and `Object_Cache::ALLOWED_KEYS` share its manifest without moving connection or persistence into the policy.

Do not introduce a configuration framework or DTO for every array. Use value objects only where stable shape and invariants already repeat.

## Bridge rules

Classify every bridge as:

- required compatibility;
- required lifecycle;
- temporary migration;
- cycle-producing and removable;
- protected.

Current service-to-owner bridges include `Script_Strategy`/`Main`, `Css_Combine`/`Main`, `Cache`/`Main`, `Critical_CSS`/`Main`, `Used_CSS`/`Main`, REST owner helpers, and facade state references. Each future item names the bridge, target owner, caller census, and removal condition.

## Compatibility removal gate

A facade or bridge can disappear only when:

1. runtime callers migrate;
2. public compatibility requirements allow removal;
3. tests prove no executable reference remains;
4. documentation and hooks stop naming it;
5. the release impact is recorded;
6. installed WordPress verification passes.

## Protected boundaries

The following remain unchanged unless the owner reverses the watchdog decision:

- LiteSpeed ESI, coexistence, header, and conditional-load contracts;
- manual plugin class loading and no PSR-4 migration;
- React `useState` architecture with no router or store;
- `advanced-cache.php` early-load behavior;
- Redis drop-in blog namespacing;
- REST capability and nonce policy;
- public RUM collection safeguards;
- uninstall and multisite isolation behavior.
