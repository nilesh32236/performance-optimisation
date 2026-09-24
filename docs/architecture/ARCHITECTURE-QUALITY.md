# Phase 3 Architecture Quality Model

This document defines how the campaign judges architecture work. The model favors clear ownership, low coupling, testable boundaries, and runtime correctness. It does not rank classes by line count.

Baseline evidence lives in `ARCHITECTURE-BASELINE.md`, `class-inventory.json`, and `DEPENDENCY-GRAPH.json`.

## Quality dimensions

### 1. Ownership

A class owns a decision when it has the state, policy, data, and tests needed to change that decision safely.

Reviewers ask:

- Which class changes when this policy changes?
- Can another class make the same decision with different results?
- Does a compatibility proxy still hold state that its target owns?
- Does a transport adapter write domain state directly?

Strong ownership gives one class the mutable state and one public contract. Shared read-only policy may live in a boundary. Shared mutable state needs an explicit owner.

### 2. Cohesion

A cohesive class has one main axis of change. Its methods and properties support that axis even when their implementation sizes differ.

Reviewers ask:

- Which unrelated reasons would change this class?
- Do data acquisition, persistence, analysis, transport, scheduling, and rendering share one class?
- Does a large parser with one coherent grammar beat several thin wrappers that split syntax from semantics?
- Does an extracted service still call its owner for every decision?

Line count is evidence. Responsibility density is the decision.

### 3. Coupling and dependency direction

The target direction is:

```text
Bootstrap and Core
        ↓
Application coordination
        ↓
Domain services
        ↓
Infrastructure and compatibility boundaries
        ↓
WordPress, filesystem, network, database, and external APIs
```

Presentation adapters call application or domain contracts. They do not own domain policy. Infrastructure does not call features. Features do not call `Main` internals. Compatibility facades sit at the outer edge and move toward a named owner.

The tokenizer records executable references. Reviewers classify each cross-domain edge as:

- legitimate domain interaction;
- coordination through an application service;
- shared policy;
- compatibility debt;
- infrastructure leakage;
- presentation leakage;
- protected complexity.

### 4. State ownership

Every static or instance state needs a lifecycle:

| State class | Meaning | Required evidence |
|---|---|---|
| Request memo | Reused within one request | Reset path, blog/request key, bounded lifetime |
| Cross-request state | Persisted in options, transients, cache, or files | Owner, invalidation, multisite behavior |
| Compatibility state | Supports a legacy API or mixed-version path | Removal condition and direct caller census |
| Singleton state | One process-wide service | Constructor/lifecycle owner and reset contract |
| Mutable global | Module or global mutation | Strong justification and isolation tests |

Static state does not fail by itself. Unclassified static state fails the architecture review.

### 5. Compatibility

A facade is a migration boundary, not a permanent dependency hub.

Each public compatibility method needs:

- a named target owner;
- a same-signature proxy while callers remain;
- a caller census;
- a removal condition;
- tests for the public surface;
- release notes when removal changes behavior.

The campaign prefers this sequence:

```text
new owner
→ direct internal callers
→ compatibility proxy
→ external caller migration
→ deprecation when required
→ safe removal
```

### 6. Testability and verifiability

A boundary improves testability when it gives tests a narrow seam without hiding behavior behind generic configuration.

Strong seams include:

- filesystem adapters;
- HTTP clients;
- cache stores;
- clock and randomness sources;
- provider adapters;
- explicit service inputs;
- small response mappers at REST, CLI, and Abilities boundaries.

Weak seams include:

- generic service containers;
- global registries with hidden mutation;
- configuration-heavy helper flags;
- static callbacks that tests cannot replace;
- interfaces with one implementation and no external seam.

### 7. Runtime integrity

Architecture changes must preserve:

- WordPress hook order and callback identity;
- option, transient, cache, and file formats;
- REST slugs, permissions, schemas, and response shapes;
- CLI command names and exit behavior;
- cron and Action Scheduler ownership;
- multisite blog isolation;
- LiteSpeed coexistence, header, and ESI contracts;
- frontend markup, settings snapshots, and asynchronous behavior;
- drop-in loading before WordPress plugins.

A green unit suite does not replace installed-site verification.

### 8. Frontend responsibility

The React SPA keeps `useState` and the server-provided `wppoSettings` snapshot. Architecture work can improve it through:

- presentational cards;
- narrow hooks for one asynchronous workflow;
- pure response mappers;
- abort and mounted guards;
- snapshot-based dirty-state logic;
- isolated polling state;
- tests around user-visible transitions.

The campaign rejects router, store, DI-container, and polling-framework adoption.

## Metric interpretation

The generated metrics act as signals. Reviewers combine them with source and runtime evidence.

| Signal | What it measures | Common false positive |
|---|---|---|
| Lines | Surface size and review cost | A cohesive parser can be large |
| Methods | Public and private surface | Thin compatibility proxies inflate the count |
| Large methods | Local reasoning and test cost | Generated-like loops can be long but cohesive |
| Fan-in | Number of classes that depend on a node | A stable support boundary can have high fan-in |
| Fan-out | Number of dependencies a node owns | A registrar can legitimately depend on many route groups |
| Static properties | Mutable state surface | Immutable constants and request memos need different treatment |
| Feature dependencies | Cross-domain reach | Some feature interactions are product requirements |
| Bridge candidates | Compatibility and facade paths | Thin stable APIs can remain intentionally public |
| Duplicate candidates | Exact token-shape matches | Different policy can produce identical syntax |
| Boundary violations | Edges against the declared direction | Protected adapters may need bounded exceptions |
| SCC size | Cycles and reciprocal dependency | The SCC does not identify the correct extraction by itself |

## Phase 3 ratchet

P3-004 records these baselines:

| Metric | Baseline | Direction |
|---|---:|---|
| Runtime SCCs | 1 | Reduce; final target 0 or a documented protected residue |
| Largest runtime SCC | 59 nodes | Reduce |
| Compatibility-classified edges | 194 | Explain and reduce only through explicit compatibility ownership |
| Compatibility SCCs | 2 | Reduce; retain only documented stable adapters |
| Largest compatibility SCC | 21 nodes | Reduce after caller migration |
| Boundary violations | 16 | Reduce; final target 0 or explicit protected exceptions |
| Bridge candidates | 229 | Reduce after caller migration |
| `Util` unique fan-in | 56 | Reduce |
| `Util` incoming executable evidence | 1,145 | Reduce |
| `Main` unique fan-out | 34 | Reduce |
| `Main` feature dependencies | 17 | Reduce |
| `Main` methods at least 80 lines | 21 | Reduce |
| Static-state nodes | 31 | Classify, then reduce or document |
| Static properties | 141 | Classify, then reduce or document |
| Exact duplicate groups | 17 | Review; reduce only semantic duplicates |
| Feature-to-feature edges | 47 | Keep only deliberate interactions |

A pull request may increase a metric for a documented bridge. It must name the follow-up item and removal condition. An unexplained increase fails the ratchet.

## SOLID policy

### Single responsibility

Split a class when independent reasons require coordinated edits. Do not split it because a method exceeds an arbitrary line count.

### Open/closed

Use providers or strategies at real extension seams such as CDN backends, image engines, cache stores, and server adapters. Keep finite branches as functions when the project has no extension contract.

### Liskov substitution

Apply it only to existing or necessary contracts. Verify that adapters honor authorization, error, and lifecycle semantics. Do not create inheritance to demonstrate the principle.

### Interface segregation

Define an interface when several implementations share a stable contract or when an external boundary needs a test seam. Reject one-interface-per-class patterns.

### Dependency inversion

Invert filesystem, HTTP, database, time, randomness, and external provider dependencies where tests or implementations benefit. Keep WordPress static APIs at outer compatibility layers.

## DRY policy

Consolidate code when the behavior and ownership are the same. Keep intentional duplication when lifecycle, security, performance, or provider semantics differ.

Every DRY item records:

- the duplicated policy;
- the canonical owner;
- the callers;
- the compatibility path;
- parity tests;
- the reason the behaviors are or are not equivalent.

The campaign rejects a generic helper whose configuration flags hide unrelated responsibilities.

## Architecture issue test

Before implementation, the issue must answer all of these:

1. Which class owns the responsibility now?
2. Which class should own it?
3. Which callers create the current coupling?
4. Which executable edges prove the dependency?
5. Which state does the owner mutate?
6. Which WordPress, REST, CLI, multisite, integration, or frontend contracts must remain stable?
7. Which regression tests run before extraction?
8. Which metric improves?
9. Which temporary bridge may increase the graph, and how will it disappear?
10. How does installed WordPress verification prove the result?

Vague goals such as “refactor Main” or “reduce Util” do not pass this test.

## Pull-request quality gate

Every architecture pull request must:

1. pin behavior with a test when risk warrants it;
2. keep one responsibility extraction;
3. run WPCS and applicable tests;
4. run the architecture generator;
5. review static-state and compatibility changes;
6. inspect the graph delta;
7. update the queue and baseline;
8. document any metric increase;
9. pass installed WordPress verification after merge;
10. leave runtime smoke evidence in the queue.

## Completion classification

The final audit classifies each hotspot as:

- **resolved**: the owner and direction now fit the model;
- **intentional**: the coupling or size supports a stable product contract;
- **protected**: watchdog, drop-in, coexistence, or lifecycle constraints require the residue;
- **acceptable**: the remaining complexity has low coupling and clear tests;
- **future debt**: a known owner and removal path remain;
- **unexplained**: no owner, evidence, or reason exists.

Phase 3 cannot finish with an unexplained major hotspot.
