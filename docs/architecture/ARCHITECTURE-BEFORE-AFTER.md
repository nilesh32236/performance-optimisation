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

The token graph is a measurement, not a score. The final ratchet is qualitative: every major hotspot has an owner, an intentional/protected reason, or a named future item.

## Inventory and responsibility movement

| Area | P3-001 baseline | Final audit | Evidence |
|---|---|---|---|
| Inventory files | 72 | 86 | New owners: `Job_Registry`, `Preload_Transport`, `Runtime_State`, `Cache_Capacity`, `Redis_Config_Policy`, `Dropin_Registry`, `Settings_Command`, and later queue owners. |
| Named methods | 2,458 | 2,547 | Facade methods are retained for compatibility; extracted owners contain the substantive logic. |
| Static-state owners | 31 | 33 | `Runtime_State` resets six site-sensitive owners; remaining owners are classified in the final audit. |
| Static properties | 141 | 143 | New extracted owners and state bridges are visible in inventory; no global mutable store was introduced. |
| Methods ≥80 lines | 238 | 229 | Large responsibility clusters decreased while explicit bridge methods were added. |
| PHPUnit tests | 2,682 | 2,783 | Added behavior and source-boundary coverage for each queue item. |
| PHPUnit assertions | 24,342 | 25,808 | Added parity, security, loader, graph, and post-merge evidence. |

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

## What intentionally remains

- The runtime SCC remains one component. The graph proves reciprocal reach, but not that one extraction will remove the whole component. It is classified as future debt with a removal condition.
- `Main` and `Util` facades remain for callback identity, compatibility, and gradual caller migration. They are not treated as a completed decomposition.
- Three direct feature-to-feature edges remain classified in the final audit. They have owners and follow-up conditions, not unexplained status.
- Four current boundary-violation evidence classes are accepted only as explicit protected residues or documented bridges. The architecture guard test fails on unclassified new evidence.
- `WPPO_VERSION` installed-state drift and the deployed object-cache helper-path drift remain environment observations; the live drop-in was repaired and verified during Phase 3, but the stored option is not changed by documentation-only audit work.
- The known `wp wppo verify` cache-root warning is an ownership/writability mismatch (`nobody` web owner versus CLI user), not a plugin correctness failure.

## Completion evidence

- P3-001..P3-022 each have one issue, one PR, exact-head CI/WPCS/drift gates, a full local review, and post-merge WordPress verification.
- P3-022 added deterministic architecture guards for boundary evidence, unowned schedule hooks, and static-state lifecycle vocabulary.
- The final audit head passes the generator check, architecture guard tests, full PHPUnit, PHPCS, JavaScript lint/tests/build, and `git diff --check`.
- The final audit artifacts classify all major hotspots as resolved, intentional, protected, acceptable, or future debt. No major hotspot is unexplained.
