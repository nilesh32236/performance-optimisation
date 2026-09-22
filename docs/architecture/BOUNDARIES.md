# Architecture Boundaries — Performance Optimisation Plugin

Target decomposition. Each boundary becomes (at most) one focused class behind a
compatibility facade. Extractions proceed one queue item at a time.

## PHP backend (`includes/`)

| Boundary | Owns | Extracted from | Must NOT own |
|----------|------|----------------|--------------|
| `Cache_Key` | `transient_key()`, `option_key()`, `cache_salt()` (+ stampede key helpers) | `Util` | storage I/O, TTL policy |
| `Settings_Store` | `get_settings()` memo, `set/clear_settings_cache()`, `save_settings()`, snapshots, `DEFAULTS`, sanitizer map dispatch | `Util`, `Main::__construct` backfills | feature defaults semantics |
| `Filesystem` | `prepare_cache_dir()`, `init_filesystem()`, `atomic_*`, `*_path_contained`, `atomic_write_php_verified()`, purge-fallback/rollout slot helpers | `Util` | cache policy, URL logic |
| `Url` | `cached_home_url/content_url()`, `normalize_*`, same-site/compare, redirect resolution, exclusion matching | `Util` | settings, filesystem |
| `Woo_Detect` | `is_woo_*`, excluded paths, self-test | `Util` | purge/invalidation actions |
| `Hook_Registry` | `Main::setup_hooks()` + `register_*` collaborator/hook groups | `Main` | feature implementations |
| `Scheduler` | Action-Scheduler unique enqueue/schedule probes, stampede lock acquire/release | `Util` | job payloads |
| `Http` | curl/finfo/xml/gd teardown is already in `Util`; any new shared request logic lands here, not in features | `Util`, `Cron`, `Telemetry` | feature parsing |
| `Cache` split (later) | policy vs key-gen vs storage vs invalidation vs stats | `Cache` | HTML mutation (stays in `Image_Optimisation` etc.) |

## Ownership rules

- Features call boundaries; boundaries never call features.
- `Main` is orchestration only: bootstrap, collaborator registration, hook registration,
  migrations dispatch. No new feature logic in `Main`.
- `Util` is a **facade under demolition**: new code uses the boundary class directly;
  `Util::` proxies remain for backward compat until callers migrate.
- React: `wppoSettings` global stays the server-provided snapshot; components use
  `apiCall()` + `useNotice()` + `NoticeBanner`. No new global mutable state.
  God components (`Dashboard` 2296 lines, `FileOptimization` 6228 lines,
  `App` 697 lines) split into cards/hooks, one component per PR.

## Protected (do NOT extract / do NOT delete)

- LiteSpeed ESI bridge + coexistence modes + header protocol (owner WONTFIX #1291).
- Manual `Main::includes()` loading (no PSR-4), `useState`-only SPA (no router/store libs).
- `advanced-cache.php` drop-in contract, Redis drop-in key namespacing, uninstall behavior.
