# Phase 3 Final Deep Architecture Audit

Audit date: 2026-09-24
Queue item: P3-023 / issue #1619
Authoritative graph: `DEPENDENCY-GRAPH.json`
Authoritative inventory: `class-inventory.json`
Baseline comparison: `ARCHITECTURE-BEFORE-AFTER.md`

## 1. Final verdict

Phase 3 deep-architecture work is complete under the campaign's completion rule: **no major hotspot remains unexplained**. Passing tests alone did not close the campaign; the final evidence is the tokenizer graph, generated inventory, owner/facade classifications, architecture guard fixtures, and post-merge WordPress verification for every executable queue item.

The remaining coupling is explicit. It is either:

- a protected product/lifecycle/drop-in/ESI contract;
- a deliberate compatibility facade retained for callback identity and backward compatibility;
- an acceptable low-risk remainder with focused tests; or
- named future debt with a responsible owner and removal condition.

## 2. Current canonical evidence

| Signal | Final value |
|---|---:|
| Inventory files | 86 |
| Graph files | 89 |
| Class-like nodes | 85 |
| Procedural nodes | 4 |
| Unique edges | 395 |
| Runtime edges | 394 |
| Compatibility edges | 206 |
| Loader edges | 3 |
| Cross-domain edges | 330 |
| Feature-to-feature edges | 50 |
| Boundary violations | 20 |
| Bridge candidates | 247 |
| Runtime SCCs | 1 |
| Compatibility SCCs | 1 |
| Duplicate candidate groups | 18 |
| Static-state owners | 33 |
| Static properties | 143 |
| Methods ≥80 lines | 229 |

The graph is intentionally not a quality score. New edges are not automatically regressions: explicit owner bridges are more visible than the old implicit references. The architecture guard and this classification explain every major hotspot.

## 3. Hotspot classification

| Owner / hotspot | Classification | Final rationale and follow-up |
|---|---|---|
| `Main` | intentional + future debt | Keeps construction, lifecycle, hook identity, and narrow coordination bridges. Remaining policy clusters are not hidden; future items name the owner. |
| `Util` | intentional + future debt | Compatibility/policy facade remains for public callers. Owner migrations continue one method cluster at a time. |
| `Cache` | resolved + intentional | Lifecycle and public facade stay in Cache; capacity is `Cache_Capacity`, invalidation is `Cache_Invalidator`, and combine policy is `Css_Combine`. |
| `Cache_Capacity` | resolved | Owns stats, cap policy, single-walk accounting, randomized-query guard, warning/throttle state, and oldest eviction. |
| `Critical_CSS` / `Ccss_Store` | resolved owner + future debt | Storage/staging/status is owned by `Ccss_Store`; generation/parsing future work remains explicitly named. |
| `Used_CSS` | acceptable + future debt | Generation, storage, parsing, delivery, and rollout remain one coherent feature boundary; future extraction must preserve cache purge ownership. |
| `Image_Optimisation` | acceptable + future debt | LCP and lazy/media contracts are separated where proven; remaining media/state clusters retain a named owner. |
| `Cron` | resolved | Scheduler primitives, Job_Registry, and Preload_Transport are separate boundaries; WP-Cron/AS compatibility hooks remain pinned. |
| `Runtime_State` | resolved | Six site-sensitive static owners reset on `switch_blog`; the remaining 27 static owners are classified rather than silently reset. |
| `Redis_Config_Policy` | resolved | REST and CLI share the complete value policy; Object_Cache retains connection/lifecycle ownership. |
| `Dropin_Registry` | resolved | Advanced/Object Cache mutators no longer call System_Info directly; reporting and storage remain in System_Info. |
| `Settings_Command` / `Settings_Store` | resolved | One write orchestration seam feeds the canonical settings owner; REST permissions and redaction remain adapter concerns. |
| `Admin_Auth` | resolved | REST and Abilities share capability/nonce policy; public RUM collection remains an explicit exception. |
| `Database_Cleanup_Runner` | resolved | REST/Abilities/CLI share dispatch; SQL, maps, health, and Cron scheduling remain in their owners. |
| Edge/CDN purge coordinator | resolved | One cache-clear fan-out owns per-event de-duplication while provider adapters preserve Cloudflare/Bunny/Varnish behavior. |
| LiteSpeed integration/ESI | protected | Header, coexistence, ESI, crawler, and standalone behavior are deliberate product contracts; do not split without a dedicated compatibility plan. |
| React cards/hooks | acceptable + future debt | `useAsyncWorkflow`, image polling, and PresetsCard are extracted; remaining cards follow the one-card/one-hook rule. |

## 4. Queue completion

All executable Phase 3 items P3-001 through P3-022 are merged with one issue and one PR each. P3-023 is the final audit item and records these artifacts:

- `ARCHITECTURE-FINAL.md` — final verdict, classification, and evidence.
- `ARCHITECTURE-BEFORE-AFTER.md` — P3-001-to-final graph and ownership comparison.
- Updated `ARCHITECTURE-BASELINE.md`, `ARCHITECTURE-QUALITY.md`, `INCLUDE-HIERARCHY.md`, `LOAD-ORDER.md`, `BOUNDARIES.md`, and generated graph/inventory artifacts.
- `docs/architecture/refactor-queue.yaml` — P3-023 completion and final classification.

Historical Phase 2 `FINAL-AUDIT.md` remains explicitly historical. It is not a competing closeout; the Phase 3 artifacts above supersede it for current decisions.

## 5. Installed WordPress and release evidence

| Check | Evidence |
|---|---|
| WordPress | 7.1.2 |
| PHP CLI | 8.3.33 |
| Plugin | 2.4.0 active |
| REST | 48 registered patterns, 47 concrete endpoints |
| WP-CLI | 8 `wp wppo` subcommands |
| `wp wppo verify` | 6/7; only known cache-root CLI writability warning |
| Frontend | HTTP 200 |
| Admin | HTTP 302 |
| REST root | HTTP 200 |
| Unauthenticated `system_info` | HTTP 401 |
| Object-cache drop-in | deployed/template parity, mode 0644, owner `nobody:nogroup` |
| Cron/AS | owned events present; no orphan-hook warning |
| Generator | `--check` passes on final head |
| Architecture guards | current evidence passes; forbidden/unowned/unexplained fixtures fail deterministically |
| PHPUnit | 2,783 tests / 25,808 assertions; 9 skips and 7 pre-existing deprecations |
| JavaScript | 56 Jest suites / 789 tests; lint has two pre-existing hook warnings; build passes with existing size warning |
| PHPStan | focused final owners clean; repository-wide historical baseline remains documented |

The cache-root warning is a real filesystem ownership mismatch: the web user owns the cache root while the CLI user is not writable. It is recorded as an environment warning and was not “fixed” by weakening permissions or changing site data.

## 6. Final guardrails

The architecture guard fails on:

1. a forbidden boundary edge without an owner/reason;
2. an unowned schedule owner;
3. a new static-state lifecycle classification that is not declared.

This makes future changes fail closed at the evidence layer while preserving intentional protected residues. New major hotspots must be added to the classification or removed; they cannot disappear by changing prose.

## 7. Explicit non-goals for future debt

The campaign is complete as an architecture campaign, not as an assertion that every future product feature is zero-debt. Future work must preserve:

- LiteSpeed ESI/coexistence/header behavior;
- manual loading and Loader_Map fallback without PSR-4;
- REST capability/nonce policy and public RUM exception;
- multisite transient/drop-in/cache isolation;
- object-cache password non-persistence and connection policy;
- React `useState`/API architecture and no-routing constraint;
- one-item/one-issue/one-PR workflow discipline.

Every remaining future item has a named owner, a current classification, and a removal condition. No major hotspot is unexplained.
