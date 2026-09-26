# Architecture Boundaries: Performance Optimisation Plugin

Phase 3 evidence: `ARCHITECTURE-BASELINE.md` and `DEPENDENCY-GRAPH.json`

Quality rules: `ARCHITECTURE-QUALITY.md`

A boundary owns one decision or one external capability. Extraction does not create ownership by itself; the target class must own the policy, mutable state, and tests.

## Layer model

| Layer | Current owners | Allowed direction |
|---|---|---|
| Bootstrap and Core | Plugin entry, `Loader_Map`, `Main`, `Hook_Registry`, `Preload_Buffer_Coordinator`, `Wp_Version`, activation/deactivation | Calls application, domain, and infrastructure contracts |
| Application coordination | `Scheduler`, `Cron`, `Settings_Migrations`, `Sandbox_Preview`, `Builder_Purge_Watcher` | Calls domain services and infrastructure; avoids presentation |
| Domain | Assets, Cache, CSS, Database, Edge, Images, Insight, Integrations | Calls infrastructure and shared policy; avoids `Main`, Admin, and other feature internals |
| Presentation | Rest, Abilities, Metabox, admin notices, WP-CLI, React | Calls application/domain contracts; owns transport and display only |
| Administrative security policy | `Admin_Auth` | Calls WordPress auth/nonce APIs only; owns no feature behavior |
| Infrastructure and compatibility | Support, Settings_Store, Cache_Key, `Util`, protected minify wrappers, drop-ins | Provides narrow shared services; avoids feature internals |
| Lifecycle and integrations | Activate, Deactivate, uninstall, LiteSpeed stack, Woo detection | Adapts WordPress and external runtime contracts without becoming feature policy |

The graph reports 20 strict violations against this model, plus compatibility-only cycles. A violation can be compatibility debt, protected complexity, or misplaced policy. Each executable queue issue must name which category it addresses; planning buckets are split before issue creation.

P3-022 makes this vocabulary executable. `php scripts/generate-class-inventory.php --check` now runs `Architecture_Guards` against the generated inventory and graph. The 20 current findings are classified exceptions; a new boundary finding fails. Direct WP-Cron/Action Scheduler callers must match the classified schedule-owner set and layer, and every static-state owner must use the P3-021 lifecycle vocabulary. `tests/php/ArchitectureGuardTest.php` provides fixture failures for each guard. This is a development-only guard and does not change runtime loading or scheduling.

## Shared boundaries

| Boundary | Owns | Must not own | Phase 3 evidence |
|---|---|---|---|
| `Cache_Key` | Transient, option, cache-salt, and stampede key policy | Storage, TTL, feature settings | Classified as infrastructure despite its `Cache/` path |
| `Settings_Store` | Effective settings resolution, historical in-memory backfills, blog-keyed raw/resolved memos, writes, validation dispatch, snapshots | REST/CLI response shaping, asset/minification/speculation behavior | P3-013 moved the 300-line Main resolver; historical compatibility data remains, not new feature policy |
| `Filesystem` | WP_Filesystem/native I/O, atomic writes, containment, path helpers | Cache policy, HTML mutation | Four static properties; partial test reset ownership |
| `Url` | Home/content URL memos, normalization, same-site policy, redirect parsing | Settings arrays, feature decisions | Five static properties; preload policy needs a separate allowance-preserving owner |
| `Scheduler` | Action Scheduler and WP-Cron primitives, locks, job ownership registry | Job payload policy owned by a feature | `Job_Registry` owns the canonical hook manifest; direct feature scheduling remains classified and teardown is centralized |
| `Http` | Shared cURL, finfo, XML, GD teardown | Provider request semantics | Small, stable support boundary |
| `Woo_Detect` | Woo detection and exclusion policy | Cache invalidation actions | `Util` still calls it, keeping a shared-facade edge |
| `Log` | Activity persistence and logging calls | Feature decisions | Fan-in 29; deliberate infrastructure hub, not a god class |
| `Loader_Map` | Eager files, fallback map, CLI path | Feature behavior | Three loader edges; stale-classmap safety remains required |
| `Util` | Residual canonical helpers and backward-compatible proxies | New feature policy or a new dependency hub | Fan-in 60 and 1,084 incoming executable occurrences; defaults are now a Store facade |
| `Minify_Policy` | CSS/JS enqueue eligibility, tag rewrites, minified-file checks, and containment guards | Hook registration, speculation/resource hints, preload, cache output, or settings writes | P3-014 extraction under the existing `includes/minify/` boundary; Main retains public callback facades and exclusion state bridges |
| `Preload_Buffer_Coordinator` | Core template-enhancement availability, legacy used-CSS/LCP buffer lifecycle, cache-aware post-save scheduling gates | Hook registration, speculation/resource hints, image serving, LiteSpeed lanes, Redis/drop-ins, or data migration | P3-015 dependency-light injected ports; Main retains public callback identities and ordering |

## Domain boundaries

### Frontend preload and buffering

`Preload_Buffer_Coordinator` owns the single bounded routing/lifecycle cluster behind Main's unchanged public callbacks. It selects the WP 6.9+ core template-enhancement path by availability, retains the pre-6.9 fallback guards, runs the used-CSS pipeline at most once per request, and coordinates post-save crawler-warm/used-CSS scheduling gates through injected ports. `Hook_Registry` remains the only hook registrar, so callback identity and cache (10) → used CSS (20) → LCP (30) order do not move. Speculation/resource hints, preload link policy, image serving markup, LiteSpeed crawler/ESI behavior, and data persistence remain outside this owner.

### Cache

| Owner | Current scope | Next ownership move |
|---|---|---|
| `Cache` | HTML cache policy, output buffer, storage, invalidation and CSS-combine facades | Keep lifecycle and buffer orchestration; extract capacity/accounting |
| `Cache_Invalidator` | Invalidation, purge fallback, deletes | Keep; remove temporary owner bridges when callers permit |
| `Cache_Capacity` | Static cache statistics, cap settings, byte/file accounting, randomized-query guard, oldest eviction | Keep narrow owner bridges for Cache filesystem, containment, and sibling-aware deletion |
| `Cache_Coordinator` | Cache construction, collaborator injection, and `wppo_cache_instance` filtering | Buffer lifecycle, storage, invalidation, or broad application wiring | Main keeps its unchanged public factory facade |
| `Cache_Key` | Key derivation | Keep as infrastructure |
| `Advanced_Cache_Handler` | `advanced-cache.php` drop-in lifecycle | Protect early-load contract |
| `Redis_Config_Policy` | Complete Redis key manifest, host/node safety, value bounds and enums, password precedence | Keep dependency-light; do not connect, persist, flush, or publish drop-ins |
| `Dropin_Registry` | Stateless, fail-open forwarding for post-mutation drop-in observation invalidation | Do not detect/report ownership, resolve paths, or own memo/transient/salt storage; System_Info remains owner |
| `Object_Cache` | Redis connection, reads/writes, circuit, drop-in management, config key compatibility constant | Keep lifecycle and storage behavior; route adapter construction through `Redis_Config_Policy` |
| `Bfcache` | Logged-in no-store policy | Keep narrow |

The 839-line `Cache` tail previously owned statistics, cap settings, capacity checks, and eviction. `Cache_Capacity` now owns that one accounting contract; `Cache` retains only the public facade and lifecycle/storage policy. The owner reaches Cache through narrow filesystem, root/domain, containment, and deletion bridges.

`Advanced_Cache_Handler` and `Object_Cache` call `Dropin_Registry::invalidate()` only after their existing successful-mutation conditions. The registry forwards to `System_Info::flush_dropin_cache()` when available; it does not absorb System Info's request memo, transient, or salted-cache responsibilities.

### Images

| Owner | Current scope | Next ownership move |
|---|---|---|
| `Image_Optimisation` | Image metadata, next-gen markup, picture, lazy loading, media transforms, preload facade | Extract cohesive media/metadata or conversion-state clusters |
| `Lcp_Preload` | LCP resolution, preload emission, dedup state | Keep; callers may migrate from the old facade |
| `Img_Converter` | WebP/AVIF conversion and conversion metadata | Consider an `Img_Info_Store` only after atomic and shutdown semantics are pinned |

Do not split `Image_Optimisation` by arbitrary file size. Test output markup, MIME negotiation, dimensions, lazy loading, builder compatibility, and cache-buffer integration.

### Minify

`Minify_Policy` owns the bounded frontend minification cluster extracted in P3-014: queued-style path registration, CSS/JS tag rewrites, exclusion and minified-name decisions, randomized-query protection, and filesystem containment for already-minified checks. It delegates settings and live exclusion reads through narrow `Main` bridges and calls the protected `Minify\CSS` / `Minify\JS` wrappers. It must not own hook registration, asset enqueue policy beyond this cluster, speculation/resource hints, preload, cache output, LiteSpeed coexistence policy, or settings persistence. Main keeps the public callback methods so `Hook_Registry` callback identity, filter priorities, generated filenames, frontend output, and no-op/fail-open behavior remain unchanged.

### CSS

| Owner | Current scope | Next ownership move |
|---|---|---|
| `Critical_CSS` | Fetch/parse/store orchestration, exclusions, budgets, frontend output, public generation facades | Keep public generation/frontend behavior; move further parsing only after lifecycle status is delegated |
| `Ccss_Generator` | Generation status values, salted/transient status keys, bounded retry/timeout counters, Action Scheduler/WP-Cron liveness and enqueue policy | Keep the lifecycle cluster narrow; do not own file writes, frontend output, purge, image/preload, or other features |
| `Ccss_Store` | Files, staging, promotion, variants, status projection, storage memos | Keep as the storage/status projection owner; read generation status through `Ccss_Generator` |
| `Used_CSS` | Generation, storage, parsing, delivery, rollout, purge integration | Split storage only after it stops owning coupled purge policy |
| `Css_Combine` | Combined CSS fetch, minify, write, inline budget | Keep in Assets; remove Cache owner bridges when safe |
| `Css_Safelist` | Shared CSS exclusions | Keep as a small policy boundary |

`Critical_CSS` and `Used_CSS` must not become reciprocal policy stores. Shared checksum, sanitization, and exclusion rules need one named owner.

### Insight and telemetry

| Owner | Current scope | Next ownership move |
|---|---|---|
| `Telemetry` | Local performance scan and salted audit cache | Own the cache read/invalidate contract; Insight_Query delegates to it |
| `Pagespeed` | PageSpeed transport, scan jobs, trends, LCP storage | Keep result reads, transport, jobs, and storage together |
| `Insight_Query` | Cached telemetry/PageSpeed read models and deterministic PageSpeed suggestion projection | Stay read-only; do not execute scans or invoke AI/RUM side effects |
| `RUM` | Collection, queue, aggregation, persistence, admin reads | Split blog-scoped state and admin projection |
| `AI_Adaptive` | Suggestion, model persistence/learning, speculation autotune | Keep model writes behind an explicit owner; move reaction side effects out of anomaly code |
| `Ai_Anomaly` | Math, thresholds, breach state, RUM digest | Keep; use its blog-aware memo pattern as the reset precedent |
| `Suggestion_Engine` | Cross-source recommendations | Call canonical telemetry/PageSpeed/RUM contracts |
| `System_Info` | Environment and infrastructure facts, drop-in path/ownership detection, memo/transient/salt storage | Keep reporting and storage ownership; mutators invalidate only through `Dropin_Registry` |

Fetch, persist, analyze, schedule, and display should not share one new “Service” class. `Insight_Query` is deliberately narrower: it projects existing domain reads for multiple adapters and owns no fetch, persistence, scan, RUM, or AI behavior. Create other narrow use-case owners only when the graph shows repeated calls or misplaced writes.

### Database

`Database_Cleanup` owns the cleanup method map, SQL behavior, counts, and Action Scheduler health. `Database_Cleanup_Runner` now owns the shared application dispatch used by REST, Abilities, and CLI: canonical validation, all/Action Scheduler branching, revision defaults, legacy CLI aliases, dry-run count previews, logging/action hooks, and REST table optimization. Authorization, response/output envelopes, CLI confirmation, and Cron scheduling/auto-clean remain with their existing owners.

### Edge and integrations

- `Edge_Cache` owns edge-provider configuration.
- `Edge_Purge_Coordinator` owns cache-clear fan-out and per-event identical Cloudflare transport de-duplication.
- `Edge_Purger` owns Cloudflare/Bunny edge purges, URL scope, and edge locks.
- `CDN_Purger` owns legacy service dispatch, LiteSpeed sync, and Varnish purges.
- `Cloudflare_Purger` owns Cloudflare transport.
- `LiteSpeed_Integration` owns coexistence, headers, TTL, and purge coordination.
- `LiteSpeed_Crawler` owns crawl scheduling and variants.
- `LiteSpeed_ESI` stays protected.

One priority-10 `wppo_after_cache_clear` listener now fans out through the coordinator. Identical full-zone Cloudflare transport is reused only within that event; separate providers and single-page scope remain independent.

## Presentation boundaries

### REST

`Rest` owns route registration, a compatibility authorization facade, response envelopes, throttling, and many domain handlers. Administrative capability and nonce decisions belong to `Admin_Auth`; REST and Abilities both delegate without changing their callback signatures. `Rest_Cache` and `Rest_Settings` own two handler groups, but current route callbacks point directly to service instances. Their owner bridges support compatibility and tests.

The next REST step should extract a narrow application command or response mapper. It should not redo Phase 2 route groups.

### WP-CLI

`WPPO_CLI_Command` owns eight subcommands, including `verify`. It also contains filesystem, drop-in, LiteSpeed, cron, and system diagnostics. Redis argument and stored-settings construction delegates to `Redis_Config_Policy`. Treat it as a transport and diagnostics surface, not a thin adapter.

### Abilities

`Abilities` delegates administrative capability and nonce decisions to `Admin_Auth`; shared application services must preserve the Abilities API registry. Remaining telemetry reads, URL validation, image operations, and database orchestration still require explicit extraction rather than being folded into the auth policy.

### React

The SPA keeps `useState`, `wppoSettings`, and `apiCall`. Boundaries include:

- presentational cards;
- one hook per async workflow;
- pure settings-response mappers;
- shared notice feedback;
- local polling and dirty-state ownership.

Current sizes are `FileOptimization.js` 6,320 lines, `Dashboard.js` 2,011, and `App.js` 654. One card or one hook per item keeps regressions searchable.

## Configuration ownership

A feature should not read another feature's internal settings paths. `Settings_Store` owns effective resolution, schema validation, writes, snapshots, and blog-keyed memo invalidation. Feature owners should expose typed or narrow validated access for their own settings.

Two high-value corrections:

1. Keep `Settings_Command` as the bounded write seam; Main and adapters must not bypass it for `wppo_settings` persistence.
2. Keep Redis config construction on `Redis_Config_Policy`; REST, CLI, and `Object_Cache::ALLOWED_KEYS` share its manifest without moving connection or persistence into the policy.

## Static-state lifecycle

The fresh P3-021 audit classifies generated static declarations as follows:

| Classification | Owners | Contract |
|---|---|---|
| Site-sensitive request memo | `RUM`, `AI_Adaptive`, `System_Info`, `LiteSpeed_Integration`, `Object_Cache`, `Database_Cleanup` | Feature-owned reset methods dispatched by `Runtime_State::on_switch_blog()`; no state is moved into a generic registry. |
| Selected non-site-sensitive request state | `Bfcache` | `Bfcache::reset_runtime_state()` runs at the `shutdown` boundary. `invalidation_script` and `script_staged` never cross a logical request; session persistence remains in WordPress session tokens. |
| Cross-request/persisted state | `Settings_Store`, `Filesystem`, `Url`, `Cache`, `Cache_Capacity`, `Ccss_Store`, `Img_Converter`, `Log`, `Scheduler`, `CDN`, `Used_CSS` | Options, transients, files, queues, or explicitly bounded process caches remain owned with their existing write/invalidation contracts. |
| Protected compatibility/immutable policy | `LiteSpeed_ESI`, `Asset_Manager` protected handle lists, and remaining immutable static configuration | Protected ESI behavior and safety allowlists remain unchanged; no state extraction is implied. |

`Admin_Notices`, `Lcp_Preload`, `OD_Bridge`, `Sandbox_Preview`, and the remaining Main/CSS/Image request memos are classified as follow-up request-state residues. They are not silently treated as cross-request state or included in the Bfcache extraction. A future item may give one owner a production reset after its own lifecycle and caller census.

Do not introduce a configuration framework or DTO for every array. Use value objects only where stable shape and invariants already repeat.


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
- REST and Abilities capability/nonce policy through `Admin_Auth`;
- public RUM collection safeguards;
- uninstall and multisite isolation behavior.
