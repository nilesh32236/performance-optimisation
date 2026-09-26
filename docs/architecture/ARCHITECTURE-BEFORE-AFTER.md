# Phase 3 Architecture Before / After

## Scope

This report compares the tokenizer baseline captured by P3-001 (`1d69360d`) with the Phase 3 deep-audit evidence at the final-audit head. Generated artifacts remain authoritative: `class-inventory.json`, `DEPENDENCY-GRAPH.json`, `ARCHITECTURE-BASELINE.md`, and `ARCHITECTURE-QUALITY.md`.

## Generated graph totals

| Metric | P3-001 baseline | Final audit | Delta | Meaning |
|---|---:|---:|---:|---|
| Graph files | 74 | 89 | +15 | New boundary owners and documented runtime contracts. |
| Class-like nodes | 70 | 85 | +15 | Ownership classes were added behind stable facades. |
| Procedural nodes | 4 | 4 | 0 | Entry/uninstall/template and drop-in procedural scope is stable. |
| Unique edges | 340 | 395 | +55 | Explicit bridges and new owner edges are now measurable. |
| Runtime edges | 339 | 394 | +55 | New extracted services are runtime-loaded, not hidden behind comments. |
| Compatibility edges | 191 | 206 | +15 | Compatibility facades and stable public aliases are recorded. |
| Loader edges | 3 | 3 | 0 | Manual Loader_Map loading remains the single loading policy. |
| Cross-domain edges | 300 | 330 | +30 | Narrow bridges are visible and reviewable. |
| Feature-to-feature edges | 47 | 50 | +3 | Three remaining direct feature interactions are classified future debt. |
| Boundary violations | 16 | 20 | +4 | Guardrails classify current residues; no unexplained violation is accepted. |
| Bridge candidates | 226 | 247 | +21 | Facade bridges are explicit follow-up work, not hidden coupling. |
| Runtime SCCs | 1 | 1 | 0 | One documented residual SCC remains. |
| Compatibility SCCs | 2 | 1 | -1 | Drop-in and compatibility paths were narrowed. |
| Duplicate groups | 17 | 18 | +1 | One new candidate is classified for review; no unsafe auto-merge occurred. |
| Largest runtime SCC | 59 nodes / 302 edges | 68 nodes / 341 edges | +9 / +39 | One reciprocal component remains as classified future debt. |
| Largest compatibility SCC | 21 nodes / 68 edges | 22 nodes / 72 edges | +1 / +4 | P3-017 direct `Woo_Detect` owner edges are temporary migration evidence. |
| Static-state owners / properties | 31 / 141 | 33 / 143 | +2 / +2 | P3-004 and P3-021 make lifecycle ownership explicit. |

The token graph is a measurement, not a score. The final ratchet is qualitative: every major hotspot has an owner, an intentional/protected reason, or a named future item.

## Inventory and responsibility movement

| Area | P3-001 baseline | Final audit | Evidence |
|---|---|---|---|
| Inventory files | 71 | 86 | New owners: `Job_Registry`, `Preload_Transport`, `Runtime_State`, `Cache_Capacity`, `Redis_Config_Policy`, `Dropin_Registry`, `Settings_Command`, and later queue owners. |
| Named methods | 2,456 | 2,547 | Facade methods are retained for compatibility; extracted owners contain the substantive logic. |
| Static-state owners | 31 | 33 | `Runtime_State` resets six site-sensitive owners; remaining owners are classified in the final audit. |
| Static properties | 141 | 143 | New extracted owners and state bridges are visible in inventory; no global mutable store was introduced. |
| Methods ≥80 lines | 238 | 229 | Large responsibility clusters decreased while explicit bridge methods were added. |
| PHPUnit tests | 2,682 | 2,783 | Added behavior and source-boundary coverage for each queue item. |
| PHPUnit assertions | 24,342 | 25,808 | Added parity, security, loader, graph, and post-merge evidence. |
| Jest suites / tests | 56 / 789 | 58 / 830 | P3-019/P3-020 async and polling coverage; no router/store framework added. |

## Major hotspot movement

| Hotspot | Before | After | Final classification |
|---|---|---|---|
| `Main` | God-class orchestration and policy | Orchestration plus explicit narrow policy bridges; remaining debt is classified | Intentional facade plus future debt |
| `Util` | Shared hub with broad policy | Compatibility/policy facade remains, with owner migrations reducing selected direct edges | Intentional facade plus future debt |
| `Critical_CSS` | Untouched storage/generation god class | `Ccss_Store` owns storage/staging/status; generation/parsing remain bounded | Resolved owner; future generation debt documented |
| `Cache` | Storage, accounting, invalidation, and CSS combine | Cache lifecycle/facade remains; `Cache_Capacity` and `Cache_Invalidator` own extracted concerns | Resolved owners |
| `Cron` | Scheduler primitives and transport scattered | `Job_Registry`, `Preload_Transport`, and `Scheduler` boundaries are explicit | Resolved owners; compatibility hooks preserved |
| REST/CLI | Repeated auth, settings, cleanup, and transport | Narrow adapters and `Admin_Auth`, `Settings_Command`, and `Database_Cleanup_Runner` owners | Resolved application boundaries; remaining duplication documented |
| Redis | REST-owned sanitizer, duplicate CLI path | `Redis_Config_Policy` is the one value policy; Object_Cache keeps lifecycle | Resolved policy owner |
| Drop-ins | Mutators called System_Info directly | `Dropin_Registry` neutralizes invalidation; System_Info remains reporter/storage owner | Resolved neutral boundary |
| React | Several async/card ownership gaps | `useAsyncWorkflow`, image polling, and PresetsCard extracted | Acceptable remainder with named future debt |
| LiteSpeed | Large integration hub | ESI/coexistence/header policy remains deliberately integrated and protected | Protected product contract |

## P3-001 through P3-022 ownership and metric record

| Item | Delivered owner / seam | Recorded result |
|---|---|---|
| P3-001 | Generator + schema-v2 graph + quality model | 74 graph files / 340 edges; deterministic drift gate established |
| P3-002 | `Job_Registry` | 21 owned WP-Cron hooks, 9 owned AS hooks, teardown parity; 75 nodes / 343 edges |
| P3-003 | `Preload_Transport` | Same-host bounded non-following redirects; implicit-follow Cron fetches 3→0; 76 / 344 |
| P3-004 | `Runtime_State` | Six site-sensitive reset owners dispatched on `switch_blog`; 77 / 345 |
| P3-005 | `Cache_Capacity` | Statistics/cap/accounting/eviction extracted; `Cache` 5,643→5,077 lines; 78 / 348 |
| P3-006 | `Redis_Config_Policy` | REST/CLI share complete Redis value policy; 79 / 351 |
| P3-007 | `Dropin_Registry` | Mutator→System_Info direct invalidation edges 2→0; compatibility SCC 2→1; 80 / 352 |
| P3-008 | `Settings_Command` + `Settings_Store` | Direct REST settings writes 3+→0; 81 / 355 |
| P3-009 | `Insight_Query` | Direct audit-cache readers outside Telemetry 2→0; 82 / 361 |
| P3-010 | `Database_Cleanup_Runner` | Adapter orchestrators 3→1; legacy dispatch 1→0; 83 / 366 |
| P3-011 | `Admin_Auth` | REST/Abilities auth implementations 2→1; public RUM unchanged; 84 / 368 |
| P3-012 | `Edge_Purge_Coordinator` | Shared purge listeners 2→1; duplicate Cloudflare transport up to 2→1; 85 / 369 |
| P3-013 | `Settings_Store` + `Cache_Coordinator` | Main 11,091→10,790 lines and 21→20 large methods at checkpoint; 86 / 373 |
| P3-014 | `Minify_Policy` | Main minify cluster delegated; callback/output contract preserved; 87 / 378 |
| P3-015 | `Preload_Buffer_Coordinator` | Main 10,461→10,225 lines and 20→17 large methods; buffer order preserved; 88 / 383 |
| P3-016 | Callable migration contract | `Settings_Migrations`→`Main` owner-state bridge removed; 88 / 383 |
| P3-017 | `Woo_Detect` external callers | 54 Woo proxy occurrences removed from Util evidence; public proxies retained; 89 / 395 |
| P3-018 | `Ccss_Generator` | Critical CSS 6,950→6,251 lines and 138→126 methods; 89 / 395 |
| P3-019 | `settingsResponse` + `useAsyncWorkflow` | Settings-response misses 3→0; deferred-save/abort/unmount guards; no router/store |
| P3-020 | `useImageJobPolling` | Dashboard image polling isolated; 5s/60-attempt backoff preserved; no polling framework |
| P3-021 | `Bfcache::reset_runtime_state()` | Selected request-state owner without production reset 1→0; persisted/protected state classified |
| P3-022 | `Architecture_Guards` + fixtures | Unexplained boundary/schedule/static evidence fails deterministically; current evidence passes |

P3-018 through P3-022 did not add runtime PHP graph edges; the graph remains 89 nodes / 395 edges while the React suite and async seams grow independently. The complete class heat-map, static-state inventory, protected-contract matrix, and verification limitations are in [`ARCHITECTURE-FINAL.md`](ARCHITECTURE-FINAL.md).

## What intentionally remains

- The runtime SCC remains one component. The graph proves reciprocal reach, but not that one extraction will remove the whole component. It is classified as future debt with a removal condition.
- `Main` and `Util` facades remain for callback identity, compatibility, and gradual caller migration. They are not treated as a completed decomposition.
- Three direct feature-to-feature edges remain classified in the final audit. They have owners and follow-up conditions, not unexplained status.
- Four current boundary-violation evidence classes are accepted only as explicit protected residues or documented bridges. The architecture guard test fails on unclassified new evidence.
- The live final audit confirms the deployed object-cache drop-in is byte-identical to the repository template; the historical helper-path drift is resolved. The stored `WPPO_VERSION` option was not changed by documentation-only audit work.
- Historical `wp wppo verify` evidence records a cache-root writability warning, but the final live WP-CLI 2.12 probe dispatches `wp wppo verify` successfully; the only result is the known cache-root writability warning.

## Completion evidence

- P3-001..P3-022 each have one issue, one PR, implementation/merge metadata, and historical post-merge WordPress verification recorded in the queue. P3-023 was merged through PR #1620, and the final audit live CLI recheck successfully dispatched `wp wppo verify`.
- P3-022 added deterministic architecture guards for boundary evidence, unowned schedule hooks, and static-state lifecycle vocabulary.
- The final audit refresh passes generator `--check`, focused architecture PHPUnit (27/27, 14,251 assertions), full PHPUnit (2,783/25,808), PHPCS via `vendor/bin/phpcs`, JavaScript lint (0 errors/2 existing warnings), Jest (58 suites/830 tests), and build (existing size warning). Focused raw PHPStan reports six existing architecture-tooling findings; configured PHPStan reports 21 unmatched ignore patterns. These are recorded, not hidden.
- The final audit artifacts classify all identified architecture hotspots as resolved, intentional, protected, acceptable, or future debt. No architecture hotspot is unexplained; live `wp wppo verify` dispatch and the known cache-root warning were rechecked successfully.
