# Include Hierarchy: Performance Optimisation Plugin

Phase 3 tree baseline: 2026-09-24
Authoritative metrics: `class-inventory.json` and `ARCHITECTURE-BASELINE.md`

The Phase 2 hierarchy remains live. Phase 3 changes a path only when ownership evidence requires it. This document describes the current tree; it does not preserve obsolete flat-path targets.

## Directory rule

Plugin runtime classes live one level below `includes/<Domain>/`. `Util` remains at the `includes/` root as a compatibility and shared-policy layer. The `minify/` wrappers stay in place as protected vendor-adjacent code. WordPress loads `templates/object-cache.php` as a drop-in outside the plugin class map.

New folders need an ownership reason. Do not create folders for aesthetic symmetry or line-count reduction.

## Canonical tree

```text
includes/
  Core/
    class-main.php
    class-hook-registry.php
    class-loader-map.php
    class-wp-version.php
    class-activate.php
    class-deactivate.php

  Cache/
    class-cache.php
    class-cache-key.php
    class-cache-invalidator.php
    class-cache-coordinator.php
    class-advanced-cache-handler.php
    class-bfcache.php
    class-dropin-registry.php
    class-object-cache.php

  Settings/
    class-settings-store.php
    class-settings-command.php
    class-settings-migrations.php
    class-sandbox-preview.php

  Scheduler/
    class-scheduler.php
    class-cron.php

  Support/
    class-filesystem.php
    class-url.php
    class-http.php
    class-log.php
    trait-purge-logger.php
    redis-connect-helper.php

  Assets/
    class-asset-manager.php
    class-script-strategy.php
    class-css-combine.php
    class-css-safelist.php
    class-google-fonts.php

  Images/
    class-image-optimisation.php
    class-lcp-preload.php
    class-img-converter.php

  CSS/
    class-critical-css.php
    class-ccss-generator.php
    class-ccss-store.php
    class-used-css.php

  Database/
    class-database-cleanup.php
    class-database-cleanup-runner.php

  Insight/
    class-telemetry.php
    class-pagespeed.php
    class-insight-query.php
    class-suggestion-engine.php
    class-system-info.php
    class-rum.php
    class-ai-adaptive.php
    class-ai-anomaly.php
    class-od-bridge.php

  Edge/
    class-cdn.php
    class-cdn-purger.php
    class-cloudflare-purger.php
    class-edge-cache.php
    class-edge-purge-coordinator.php
    class-edge-purger.php
    class-server-rules.php
    class-htaccess-handler.php
    class-header-emitter.php

  Integrations/
    class-litespeed-integration.php
    class-litespeed-crawler.php
    class-litespeed-esi.php
    class-woo-detect.php
    class-builder-purge-watcher.php

  Admin/
    class-rest.php
    class-rest-cache.php
    class-rest-settings.php
    class-abilities.php
    class-metabox.php
    class-admin-notices.php
    class-perf-translations.php
    class-wppo-cli-command.php

  Compatibility/
    class-core-tweaks.php
    class-llms.php

  class-util.php

  minify/
    class-css.php
    class-html.php
    class-js.php

templates/
  object-cache.php
  perf-translations.php
```

## Scope counts

| Scope | Inventory entries | Loader treatment |
|---|---:|---|
| Runtime plugin source under `includes/` | 81 | 80 class-like nodes plus the Redis helper; Loader_Map completeness applies |
| Protected minify wrappers/policy | 4 | Loaded through Main/Composer use paths; excluded from Loader_Map completeness |
| Redis object-cache drop-in | 1 | WordPress early-load contract; excluded from Loader_Map completeness |
| Total inventory entries | 86 | Generated schema-v2 inventory |

The dependency graph adds four procedural runtime files: the plugin entry, `uninstall.php`, the Redis helper, and `templates/perf-translations.php`. Combined with 85 class-like nodes, the graph has 89 nodes.

## Ownership by directory

### Core

`Core` owns bootstrap, loader coordination, hook registration, runtime version gates, lifecycle callbacks, and the bounded `Preload_Buffer_Coordinator`. The coordinator receives settings/safety/scheduling ports rather than a Main reference. `Runtime_State` owns the production switch_blog reset registry while feature owners retain their reset methods. `Main` may depend on lower layers. Lower layers do not depend on `Main` private implementation; current reverse edges are recorded as bridge debt.

### Cache

`Cache` owns static HTML cache policy, storage, buffer orchestration, and the capacity/accounting tail. `Cache_Invalidator` owns invalidation and purge fallback. `Cache_Coordinator` owns only Cache construction, collaborator injection, and the public injection filter while Main retains its factory facade. `Cache_Key` stays an infrastructure primitive even though it lives here. `Object_Cache` owns Redis connection, circuit, persistence, and drop-in lifecycle; `Redis_Config_Policy` owns the complete key manifest and value normalization used by REST and CLI. `Dropin_Registry` is a stateless forwarding seam only: Advanced/Object drop-in mutators use it to request System_Info cache invalidation without taking over reporting, path/ownership detection, or storage.

### Settings

`Settings_Store` owns effective options resolution, historical in-memory backfills, blog-keyed raw/resolved memo invalidation, validation, snapshots, and persistence. `Settings_Command` owns the bounded write orchestration seam. `Settings_Migrations` owns one-time migration payloads, and `Sandbox_Preview` owns staged preview settings. Main keeps only its compatibility facade and local injectable snapshot.

### Scheduler

`Scheduler` owns shared enqueue, schedule, and lock primitives. `Job_Registry` owns the canonical WP-Cron, fallback, legacy, and Action Scheduler hook sets; `Preload_Transport` owns bounded same-host redirect handling for Cron warmup. `Cron` owns job registration and payloads. Deactivation and uninstall consume the registry without duplicating hook lists.

### Support

`Support` owns filesystem, URL, HTTP, logging, and the Redis helper. These classes may use settings or other narrow boundaries. They should not call feature internals.

### Assets

`Assets` owns script policy, CSS combination, safelists, Google Fonts, and per-page asset management. `Script_Strategy` and `Css_Combine` retain owner bridges until callers migrate.

### Images

`Images` owns frontend image delivery, LCP preload resolution, and format conversion. `Lcp_Preload` owns preload state; `Image_Optimisation` still exposes its compatibility surface.

### CSS

`CSS` owns critical and used CSS generation, delivery, policy, and status. `Ccss_Generator` owns Critical CSS status values, retry/timeout state, and queue liveness/enqueue policy; `Ccss_Store` owns critical CSS file storage and status projection. `Critical_CSS` retains public generation/frontend facades and actual fetch/parse/output orchestration. Used CSS still owns a coupled purge path, which the graph records for later coordination.

### Database

`Database_Cleanup` owns cleanup SQL, batching, eligibility, counts, and Action Scheduler cleanup health. `Database_Cleanup_Runner` owns only shared adapter dispatch, aliases, previews, activity, and post-cleanup optimization policy. REST/Abilities/CLI retain authorization, confirmation, and response/output formatting; Cron remains the scheduled `auto_clean()` caller.

### Insight

`Insight` owns telemetry, PageSpeed, RUM, AI, suggestions, system facts, and Optimization Detective adaptation. `Insight_Query` is the narrow read-only projection consumed by REST, Abilities, and CLI; it delegates cached telemetry/PageSpeed reads to their domain owners and never executes scans or owns AI/RUM side effects. Fetch, storage, analysis, scheduling, and presentation still overlap in several classes and form future queue work.

### Edge

`Edge` owns CDN and cache-provider configuration, cache-clear fan-out, purge transport, server rules, `.htaccess`, and response headers. `Edge_Purge_Coordinator` suppresses only identical full-zone Cloudflare transport within one event; provider adapters retain their own scope, locks, LiteSpeed sync, Varnish, Bunny, and logging behavior.

### Integrations

`Integrations` owns LiteSpeed, WooCommerce detection, and builder purge adaptation. LiteSpeed behavior stays protected. Builder scheduling should migrate to the central job registry.

### Admin

`Admin` owns REST, Abilities, metaboxes, notices, translations, and WP-CLI transport. `Admin_Auth` is the dependency-light shared policy for administrative capability and `wp_rest` nonce decisions; public RUM token/IP/rate-limit validation remains in `RUM`. Domain policy should move behind application or domain services before these classes grow further.

### Compatibility

`Compatibility` owns WordPress behavior adjustments and virtual compatibility surfaces. These classes may call lower layers. Feature internals should not call them for ordinary domain policy.

### Util

`Util` remains a public static compatibility layer with residual canonical helpers. Phase 3 tracks each method as one of:

- canonical shared responsibility;
- compatibility proxy;
- true generic helper;
- legacy implementation;
- duplicate;
- dead code;
- wrong owner.

The class stays at the root until its remaining canonical responsibilities have named targets. Its current 56-node fan-in prevents a cosmetic move.

### Minify and drop-ins

The minify wrappers stay protected and vendor-adjacent. Their exact-shape duplication is a review signal, not permission to merge their behavior. `templates/object-cache.php` keeps the WordPress drop-in class and blog-aware key namespacing.

## Path-change safety

Before moving any runtime file, update and verify:

1. the file and namespace-preserving Git move;
2. `Loader_Map::eager_files()`, `litespeed_stack_files()`, and `fallback_map()` when applicable;
3. the plugin entry's stale-classmap requires;
4. Composer classmap regeneration;
5. Action Scheduler or vendor bootstrap references;
6. early-load and drop-in references;
7. `require`, `include`, `class_exists`, `method_exists`, and `Reflection*` references;
8. test bootstrap and direct test paths;
9. JavaScript, build scripts, workflows, and release scripts;
10. architecture docs and generated artifacts;
11. the installed site and owned drop-ins.

A class-name or namespace-preserving move still needs this checklist. Autoload changes can fail before any method runs.

## Generator scope

`php scripts/generate-class-inventory.php` uses deterministic one-level domain globs for the current tree and includes the object-cache template. It does not hardcode a class-name list.

The tokenizer records:

- imports (including grouped imports);
- static calls and class-constant references;
- instance calls;
- new expressions;
- inheritance;
- trait use;
- class-string references;
- class, method, interface, and trait existence probes;
- qualified external/WordPress function calls;
- reflection references;
- loader and file references;
- WordPress, filesystem, network, and database signals;
- static state;
- method-size metrics;
- exact-shape duplicate candidates.

Comments and docblocks appear only in `documentation_references`. They never create graph edges. Dynamic instance receivers remain visible as `instance_calls` or `dynamic_references` without a fabricated target edge.

## Phase 3 hierarchy decisions

The fresh audit does not justify a broad directory reshuffle. The existing domain directories match the code's primary ownership axes. Phase 3 should:

- `Cache_Capacity` now lives under `Cache/`; it owns statistics, cap settings, byte/file accounting, randomized-query detection, and oldest eviction while `Cache` keeps the public facade;
- add a runtime-state owner under `Core/` or `Support/` only after its lifecycle contract is proven;
- add narrow application services near their adapter or domain boundary, not in a generic `Services/` folder;
- keep REST, CLI, React, protected integrations, and drop-ins at their current edges.

A future folder proposal needs graph evidence, loader impact, and a concrete migration path.
