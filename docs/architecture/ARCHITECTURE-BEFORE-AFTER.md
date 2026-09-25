# Phase 3 Before → After (P3-001 → P3-022)

Captured: 2026-09-25 UTC, `HEAD` after P3-022 merge (`2e69007e`).
Generator: `php scripts/generate-class-inventory.php` + `--check` clean.
Scope: schema-v2 tokenizer graph (89 files: 85 class-like nodes + 4 procedural nodes).
Closeout: `ARCHITECTURE-FINAL.md` classifies every remaining hotspot; no metric below is unexplained.

## Campaign totals

| Metric | P3-001 baseline (`phase3.baseline_metrics`) | Current (generated) | Delta |
|---|---:|---:|---|
| Inventory files | 71 | 86 | +15 (new owners) |
| Graph nodes | 74 | 89 | +15 |
| Unique edges | 340 | 395 | +55 (explicit owner edges) |
| Runtime edges | 339 | 394 | +55 |
| Compatibility edges | 191 | 206 | +15 (documented lifecycle bridges) |
| Loader edges | 3 | 3 | 0 |
| Cross-domain edges | n/a (321 at first full audit) | 330 | recorded |
| Feature-to-feature edges | 47 | 50 | +3 net (7 P3-017 Woo_Detect owner edges, minus P3-007 −1, plus P3-018 CSS lifecycle) |
| Boundary violations | 16 | 20 | +4 (new explicit owner/protected edges, all classified in guards) |
| Bridge candidates | 226 | 247 | +21 (facade + owner bridges, each with a follow-up owner) |
| Runtime SCCs | 1 | 1 | 0 |
| Largest runtime SCC | 59 nodes / 302 edges | 68 nodes | +9 nodes (new owners join the SCC through documented bridges) |
| Compatibility SCCs | 2 | 1 | −1 (P3-007 removed the System Info/drop-in pair) |
| Largest compatibility SCC | n/a | 22 nodes | Woo_Detect ↔ Util facade cycle (P3-017) |
| Static properties | 141 | 143 | +2 (Ccss_Generator memo, Settings_Store resolved memos) |
| Static-state owners | 31 | 33 | +2 (new owners, all classified by P3-021/P3-022) |
| Exact duplicate groups | 17 | 18 | +1 review signal (P3-018 CSS lifecycle) |
| Util unique fan-in | 56 | 60 | +4 (temporary duplicate caller edges; evidence −61) |
| Util incoming executable evidence | 1,145 | 1,084 | −61 (P3-017 Woo-proxy migration) |
| Main unique fan-out | 34 | 38 | +4 (explicit owner edges) |
| Main feature dependencies | 17 | 19 | +2 (documented bridges) |

Edge-count growth is the facade effect: every extraction adds thin proxies and explicit owner edges while removing hidden coupling. P3-022 guardrails pin the current findings and fail on any new unexplained edge, schedule API, or state owner.

## Per-item ratchets (P3-002 → P3-022)

| Item | Change | Graph / ratchet result |
|---|---|---|
| P3-002 scheduler registry | `Job_Registry` owns 21 WP-Cron + 9 AS hooks; teardown parity 100% | 72 files / 75 nodes / 343 edges |
| P3-003 preload transport | `Preload_Transport` bounds same-host redirects; implicit-follow fetches 3 → 0 | 73 / 76 / 344 |
| P3-004 runtime state | `Runtime_State` resets 6 site-sensitive owners on `switch_blog` | 74 / 77 / 345 |
| P3-005 cache capacity | `Cache_Capacity` owns stats/cap/eviction; Cache 5,643 → 5,077 lines | 75 / 78 / 348 |
| P3-006 Redis policy | `Redis_Config_Policy` owns key manifest + values; bridges 231 → 230 | 76 / 79 / 351 |
| P3-007 drop-in registry | `Dropin_Registry` seam; Cache→System_Info invalidation edges 2 → 0; compat SCCs 2 → 1 | 77 / 80 / 352 |
| P3-008 settings command | `Settings_Command` routes writes; direct writes outside Store 3 → 0 | 78 / 81 / 355 |
| P3-009 insight query | `Insight_Query` read boundary; direct audit-cache readers 2 → 0 | 79 / 82 / 361 |
| P3-010 cleanup runner | `Database_Cleanup_Runner`; adapter orchestrators 3 → 1 | 80 / 83 / 366 |
| P3-011 admin auth | `Admin_Auth` shared policy; independent implementations 2 → 1 | 81 / 84 / 368 |
| P3-012 edge purge | `Edge_Purge_Coordinator`; duplicate Cloudflare transport ≤2 → 1 per event | 82 / 85 / 369 |
| P3-013 options/lifecycle | `Settings_Store` resolution + `Cache_Coordinator`; Main −301 lines, −1 large method | 83 / 86 / 373 |
| P3-014 minify policy | `Minify_Policy` bounded cluster; speculation deferred | 84 / 87 / 378 |
| P3-015 preload/buffer | `Preload_Buffer_Coordinator`; Main 20 → 17 large methods | 85 / 88 / 383 |
| P3-016 bridge cleanup | `Settings_Migrations` callable options/invalidation; Main bridge removed | 85 / 88 / 383 |
| P3-017 Woo callers | 7 callers → `Woo_Detect`; Util evidence 1,138 → 1,084; proxies retained | 86 / 89 / 390 → 395 |
| P3-018 CSS lifecycle | `Ccss_Generator` status/retry/queue owner; Critical_CSS 6,950 → 6,251 lines | 86 / 89 / 395 |
| P3-019 React lifecycle | `settingsResponse.js` + `useAsyncWorkflow`; response misses 3 → 0 | PHP unchanged; Jest 826 |
| P3-020 React polling | `useImageJobPolling.js`; Dashboard polling isolated | PHP unchanged; build regen |
| P3-021 static state | `Bfcache::reset_runtime_state()` at shutdown; selected unclassified 1 → 0 | 86 / 89 / 395 |
| P3-022 guardrails | `class-architecture-guards.php` + `ArchitectureGuardTest`; new unexplained evidence fails | 86 / 89 / 395, no runtime change |

P3-017's transient 390-edge count settled at 395 after P3-018's CSS lifecycle edges; both deltas are documented owner bridges.

## God-class lines (Phase 2 → Phase 3 close)

| Class | Phase 2 close | Phase 3 close | Note |
|---|---:|---:|---|
| `Main` | 11,091 lines / 235 methods | 10,211 lines / 236 methods | −880 lines; facades retain callback identity |
| `Util` | 5,006 lines / 156 methods | 4,791 lines / 156 methods | Canonical defaults moved to Store; proxies stay |
| `Cache` | 5,643 lines / 161 methods | 5,077 lines / 167 methods | Capacity extracted; facade bridges inflate count |
| `Critical_CSS` | 6,964 lines / 138 methods | 6,251 lines / 126 methods | Store + Generator extracted |
| `Rest` | 3,891 lines / 76 methods | 3,656 lines / 76 methods | Cache/Settings slices + policy moves |

Method-count increases are retained thin proxies per the facade-first rule, not new responsibilities.

(End of file)
