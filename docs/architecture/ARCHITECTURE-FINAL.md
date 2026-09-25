# Phase 3 Final Architecture Audit (P3-023)

Captured: 2026-09-25 UTC, `HEAD` after P3-022 merge (`2e69007e`).
Docs-only audit; no runtime behavior changes.
Evidence: `class-inventory.json` (86 files), `DEPENDENCY-GRAPH.json` (89 nodes / 395 edges),
`ARCHITECTURE-BASELINE.md`, `ARCHITECTURE-QUALITY.md`, `ARCHITECTURE-BEFORE-AFTER.md`.
Generator `--check` passes on a clean tree. Queue: every P3 item `merged` except P3-023 (this audit);
the only explicit future item is P3-024.

Classification vocabulary comes from `ARCHITECTURE-QUALITY.md` §Completion:
resolved, intentional, protected, acceptable, future debt. There are zero unexplained major hotspots.

## 1. Resolved

- Scheduler teardown hole (builder drift/upgrade purge leaks) → `Job_Registry` owns 21 WP-Cron + 9 AS hooks; teardown parity 100% (P3-002).
- Implicit-follow preload transport (3 Cron fetch seams) → `Preload_Transport` bounded same-host policy (P3-003).
- Six site-sensitive static owners without reset → `Runtime_State` `switch_blog` dispatch (P3-004).
- Cache 839-line capacity tail → `Cache_Capacity` owner (P3-005).
- Redis value policy in REST only → `Redis_Config_Policy` shared by REST + CLI (P3-006).
- Cache→System_Info invalidation edges (2 → 0) → `Dropin_Registry`; compatibility SCCs 2 → 1 (P3-007).
- Direct `wppo_settings` writes outside Store (3 → 0) → `Settings_Command` (P3-008).
- Split telemetry/PageSpeed reads → `Insight_Query` boundary; direct audit-cache readers 2 → 0 (P3-009).
- Triplicated DB cleanup orchestration → `Database_Cleanup_Runner` (P3-010).
- Duplicated admin capability/nonce checks → `Admin_Auth` (P3-011).
- Duplicate Cloudflare transport per event (≤2 → 1) → `Edge_Purge_Coordinator` (P3-012).
- Main options resolution/invalidation + cache construction → `Settings_Store` / `Cache_Coordinator` (P3-013).
- Main minification cluster → `Minify_Policy` (P3-014).
- Main preload/buffer lifecycle → `Preload_Buffer_Coordinator` (P3-015).
- `Settings_Migrations` → `Main` owner-state bridge → callable contracts (P3-016).
- Woo external callers on `Util` proxies → direct `Woo_Detect` edges; evidence 1,138 → 1,084 (P3-017).
- Critical CSS status/retry/queue lifecycle → `Ccss_Generator`; Critical_CSS −699 lines (P3-018).
- React settings-response misses (3 → 0) + async races → `settingsResponse.js` + `useAsyncWorkflow` (P3-019).
- Dashboard polling tangle → `useImageJobPolling.js` (P3-020).
- Unclassified request-state residue → `Bfcache::reset_runtime_state()` at shutdown (P3-021).
- No executable guard against new coupling → P3-022 guardrails (boundary/schedule/static-state); fixture-pinned failures.

## 2. Intentional (stable product contracts)

- **`Util` hub** (fan-in 60, 1,084 incoming occurrences): remaining callers use canonical helpers (checksum/sanitize/bounds, query policy) or one-line compatibility proxies with named owners. High fan-in on a stable support boundary is expected; migration continues per-cluster, never mechanically.
- **`Main` hub** (fan-out 38, 19 feature deps, 236 methods): orchestration, callback identity, and public facades are the product contract. Remaining policy (speculation, resource hints, image serving) stays until a bounded extraction proves itself.
- **`Log` hub** (fan-in 29): single activity-logging contract; thin stable API, intentionally public.
- **Facade proxies + `@internal` bridges** across extracted services: migration boundaries, each with a named target owner and caller census. Method-count inflation (Main 236, Cache 167) is the documented facade effect.
- **Mixed-version `class_exists`/`method_exists` guards**: intentional compatibility debt with removal conditions, not dead code.
- **Duplicate-shape groups (18)**: exact token syntax, not semantic equivalence; consolidation requires parity proof per the DRY policy (notably minify CSS/JS protected shapes and Critical/Used CSS parity shapes).

## 3. Protected (watchdog / lifecycle constraints — do not refactor)

- LiteSpeed ESI bridge, coexistence modes, `X-LiteSpeed-*` protocol, crawler conditional loading.
- Manual loading (`Main::includes()` + `Loader_Map`); no PSR-4 migration.
- `advanced-cache.php` early-load contract and static-cache directory layout.
- Redis object-cache drop-in, blog key namespacing, `wppo-redis-config.php` path.
- Multisite transient/option isolation (`Util::transient_key()`, blog-keyed memos).
- REST namespace/slugs, `manage_options` + `X-WP-Nonce` via `Admin_Auth`; public `rum_collect` token/IP/rate-limit path.
- `useState`-only React SPA, no router/store; committed `build/` output.
- Protected minify wrappers (`Minify\HTML/CSS/JS`) and their `Sandbox_Preview`/`Img_Converter` edges.
- `Bfcache` shutdown reset and `Runtime_State` six-owner registry semantics.

## 4. Acceptable (low coupling, tested, no action required)

- Method-count facade inflation (Cache 161 → 167, Main steady at 236): thin delegates with parity tests.
- Edge-count growth (+55 campaign): new classes + explicit owner edges replacing hidden coupling.
- 68-node runtime SCC: reciprocal reach is recorded and guarded; each future extraction must still name a smaller owner and path (SCC size alone never justifies a split).
- 20 boundary findings and 247 bridge candidates: all classified in `class-architecture-guards.php`; the gate fails only on new unexplained evidence.
- Per-file request memos with reset wiring; persisted options/files/queues under existing invalidation contracts.
- React remainder (FileOptimization cards, Woo self-test transport): bounded, tested, no pressing defect.

## 5. Future debt (explicit owner + removal path)

- **P3-024 (queued, only active future item)**: AI model persistence/learning ownership (`AI_Model_Store`/policy under Insight). AI_Adaptive direct model persistence/remote-policy responsibilities 2 → 0. No opt-in, security, or response-shape change.
- **Util residual clusters**: settings snapshots, filesystem, URL, cache-key, HTTP caller migration continues per-cluster (P3-017 pattern); proxies remain until compatibility permits removal.
- **Service→Main reverse bridges**: Css_Combine, Script_Strategy, Image_Optimisation, Hook_Registry families remain queued as bounded follow-ups (P3-016 covered Settings_Migrations only).
- **CSS/image tails**: Used CSS storage/purge coupling, Image_Optimisation media/metadata clusters, parser bridges keeping the SCC connected.
- **React tails**: FileOptimization card separation (one-card-per-PR precedent), Woo self-test transport consolidation.
- Superseded records FUT-002/003/004/005 stay superseded-by P3 items for traceability; FUT-001 and FUT-006 are merged.

## 6. Guardrails and runtime contracts

- `scripts/architecture/class-architecture-guards.php` (loaded by the generator, evaluated in `--check`): 20 classified boundary findings, 11 schedule-owner nodes, 33 static-state owners with P3-021 lifecycle vocabulary. `ArchitectureGuardTest` pins all three failure modes with fixtures; current evidence passes.
- `ArchitectureInventoryTest`: schema, file-set completeness, reference categories, classifications, drift.
- Runtime contracts preserved across the campaign: hook order/callback identity, option/transient/cache/file formats, REST slugs/permissions/schemas/shapes, CLI names/exit behavior, cron/AS ownership, multisite isolation, LiteSpeed/ESI contracts, frontend markup/snapshots/async behavior, drop-in early loading.
- Installed-site verification after every merge: `wp wppo verify` 6/7 (known environmental CLI-writability warning only), 48 route patterns / 47 endpoints, 8 CLI subcommands, frontend 200 / admin 302.

## 7. Queue status (terminal)

P3-001 … P3-022 `merged`. P3-023 closes with this audit (`merged`). P3-024 `queued` is the sole
explicit future item. No active campaign backlog remains. `ARCHITECTURE-QUALITY.md` completion rule
holds: no unexplained major hotspot.

(End of file)
