# Architecture Principles — Performance Optimisation Plugin

Owner: architecture-refactoring campaign. This file is the design contract for every
refactor PR. It complements (never overrides) `wppo-agent-rules.md` (watchdog),
`AGENTS.md`, and `.agents/AGENTS.md`.

## 1. Pragmatic SOLID

- **SRP first.** One class owns one responsibility cluster. God classes
  (`Main` 232 methods, `Util` 170 methods, `Cache` 135 methods — see
  `docs/architecture/class-inventory.json`) are decomposed one extraction at a time.
- **Open/Closed where useful:** new behavior via new methods/classes + filters,
  not by editing hot paths. Never break `wppo_*` hooks, REST contracts, or option names.
- **Dependency Inversion only at real boundaries** (storage, filesystem, HTTP, scheduler).
  Do NOT create an interface per class. An interface earns its existence when two
  implementations exist or a test seam is required at a boundary.
- **Composition over inheritance.** No new base classes for sharing helpers;
  prefer small collaborators + facades.

## 2. DRY with intent

Consolidate duplicated settings access, validation, URL normalization, filesystem
logic, capability checks, hook registration — **unless** the duplication is
intentional (different lifecycle, security level, or compat requirement).
Intentional duplication must carry a comment citing why (precedent:
`class-image-optimisation.php` D-13/D-14 dual-path notes).

## 3. WordPress is the framework

Preserve hooks, filters, REST namespace `performance-optimisation/v1`, option names,
multisite behavior (`Util::transient_key()` blog prefix), WooCommerce/Elementor/
LiteSpeed/CDN/Cloudflare compat, and `WP_Filesystem` vs native-I/O choices
(see `.jules/bolt.md` streaming lesson). Never swap a WP API for generic PHP
without a compat analysis in the issue.

## 4. Performance is a gate

No expensive abstraction in hot paths (output buffering, regex callbacks, per-image
loops). Static memo caches stay per-request, keyed by `get_current_blog_id()`
where multisite-switchable, and registered in `reset_all_caches()` + test bootstrap.
A refactor that regresses a hot path fails review even if cleaner.

## 5. Compatibility facades, incremental migration

Moved public/static behavior keeps a thin `Util::`/`Main::` proxy (deprecated
only after callers migrate). No big-bang rewrites, no namespace migration bundled
with extraction, no public API removal.
