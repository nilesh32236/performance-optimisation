# Architecture Principles: Performance Optimisation Plugin

These principles govern the incremental architecture campaign. `wppo-agent-rules.md` remains the final authority for protected behavior and merge safety.

## 1. Clear ownership before extraction

Move a responsibility only after identifying its current owner, target owner, callers, mutable state, and tests. A new file does not create an owner by itself.

Phase 3 starts from 11,091 lines and 235 methods in `Main`, 5,006 lines and 156 methods in `Util`, and 5,643 lines and 161 methods in `Cache`. These counts describe review pressure; they do not set line-count targets.

## 2. Dependency direction

Prefer:

```text
Bootstrap/Core
→ application coordination
→ domain services
→ infrastructure and compatibility
→ external systems
```

Features do not call `Main` private implementation. Infrastructure does not call feature internals. Presentation adapters call application or domain contracts instead of writing domain state.

The schema-v2 graph records 16 strict boundary violations, one 59-node runtime SCC, and two compatibility-only SCCs. Those findings set priorities; they do not dictate one giant rewrite.

## 3. Pragmatic SOLID

- Apply SRP to independent reasons to change.
- Use providers and strategies at real extension seams.
- Verify substitutability only where shared contracts exist.
- Define interfaces for external seams or multiple real implementations.
- Invert filesystem, HTTP, database, time, randomness, and provider dependencies where it improves tests or adapters.

Do not add a DI container, an interface per class, inheritance for demonstration, or a DTO for every settings array.

## 4. Compatibility with a removal path

Public, static, hook-visible, and reflection-visible behavior keeps a proxy until callers migrate. Each facade method needs a target owner, caller census, removal condition, and release impact.

`Util` remains a compatibility layer, not a home for new feature policy. The current graph records 60 incoming source nodes and 1,084 executable references, so caller migration matters more than moving the file.

## 5. DRY with semantic proof

Consolidate validation, sanitization, permission checks, settings writes, cache keys, URL policy, response mapping, hook registration, and detection only when the behavior matches.

Intentional duplication must document the lifecycle, security, performance, or provider difference that requires it. Exact tokenizer shape matches remain review candidates until source and tests confirm semantic equivalence.

## 6. Explicit state lifecycle

Classify every static property as request memo, cross-request state, compatibility state, singleton state, or mutable global. Prove reset, blog switching, and test isolation for site-sensitive state.

Static state alone is not a defect. Unclassified shared state is a defect.

## 7. WordPress is the runtime contract

Preserve hook order, filters, option and transient names, multisite isolation, REST permissions, CLI output, cron ownership, builder compatibility, and LiteSpeed behavior.

Keep:

- LiteSpeed ESI, coexistence, headers, and conditional loading;
- manual plugin class loading and no PSR-4 migration;
- the React `useState` architecture with no router or store;
- `advanced-cache.php` early-load behavior;
- Redis drop-in blog namespacing;
- public RUM safeguards;
- committed build output.

## 8. Performance belongs in the design review

Refactoring must not add hot-path I/O, repeated remote requests, new timers, or avoidable output-buffer work. React work should also avoid unrelated eager fetches and whole-tree polling rerenders.

A cleaner class that slows cache generation, image delivery, CSS processing, or the admin SPA fails the review.

## 9. Evidence before claims

Use the current repository and installed WordPress as authority. Regenerate the class inventory and tokenizer graph after runtime source changes. Treat graph metrics as signals that require source, history, tests, and runtime evidence.

The tokenizer excludes comments from runtime edges. It records dynamic references without inventing a target when PHP cannot resolve one from syntax. Compatibility probes, loader edges, and facade calls receive separate classifications.

## 10. One verified step

Each queue item owns one responsibility, one GitHub issue, and one pull request. Pin behavior, extract, run gates, inspect the graph delta, merge through CI, verify installed WordPress, and update the queue before the next item.

One verified architectural step is worth more than a broad file shuffle.

Queue records marked `type: epic` or `status: planning` are non-executable buckets until split into a single responsibility and issue; only one executable item can be active at a time.
