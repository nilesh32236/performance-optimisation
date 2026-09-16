# PHP 8.4 / 8.5 Compatibility — Deprecation Sweep, JIT & OPcache Guidance

> Scope: WordPress 6.2+, PHP 8.2 minimum. Newer runtimes (8.4/8.5) are supported
> via additive, version-gated code only — the plugin never fatals on older PHP.

## 1. Sweep results (issue #1219)

- **Implicitly-nullable signatures (PHP 8.4):** `includes/`, `templates/`, and
  `tests/php/` were swept for typed `Type $x = null` parameters. All plugin
  signatures already use explicit `?Type = null`; no changes were required on
  this pass. Vendor Action Scheduler 4.1.0 `save_action()` surfaces
  (`ActionScheduler_Store`, `ActionScheduler_DBStore`,
  `ActionScheduler_HybridStore`, `ActionScheduler_wpPostStore`) are already
  explicit-nullable (`?DateTime ... = null`) and are now pinned by
  `PhpDeprecationHygieneTest::test_action_scheduler_save_action_is_explicit_nullable()`.
  No vendor bump was needed — a bump would only be taken for a proven
  `E_DEPRECATED` on the `save_action` surface.
- **Dynamic properties (PHP 8.2+):** no `__get`/`__set` magic or undeclared
  `$this->...` assignments were found in `includes/`; no
  `#[\AllowDynamicProperties]` shims were added.
- **PHP 8.5 resource-teardown no-ops** (`curl_close()`, `curl_multi_close()`,
  `curl_share_close()`, `finfo_close()`, `xml_parser_free()`,
  `imagedestroy()`): direct calls are centralized behind `Util` helpers in
  `includes/class-util.php` — `close_curl_handle()`,
  `close_curl_multi_handle()`, `close_curl_share_handle()`,
  `close_finfo_handle()`, `free_xml_parser()`, `destroy_gd_image()` — each with
  an `is_php85_or_greater( ?string $php_version = null )` gate (test seam) and
  a fail-open legacy path below 8.5. Call sites (`class-telemetry.php`,
  `class-litespeed-crawler.php`, `class-img-converter.php`) use the helpers;
  no raw close calls remain in plugin code.
- **Cron/scheduler hotspot:** `includes/class-cron.php`
  (`is_woo_excluded_url()`, `get_rest_route_param()`) is covered by
  `PhpDeprecationHygieneTest::test_cron_woo_exclusion_paths_are_null_safe()`
  under the zero-notice gate.

## 2. Zero-notice gate

- `tests/php/bootstrap.php` defines `WP_DEBUG=true` so deprecations are visible.
- `tests/php/PhpDeprecationHygieneTest.php` installs a per-test handler that
  collects `E_DEPRECATED`/`E_USER_DEPRECATED` and fails the test in
  `tearDown()` when any notice was raised.
- Run: `composer test` on PHP 8.2, 8.3, 8.4, and 8.5 with `WP_DEBUG` on; scan
  logs for `Deprecated`. Any single-file fix that regresses older PHP reverts
  to the guarded legacy branch (fail-open to prior behavior), never fatal.

Proposed CI extension (requires workflow permissions, not applied here):
extend `psalm-wpcs-check.yml` from the single PHP 8.2 job to an 8.2–8.5
matrix running `parallel-lint` plus `composer test`, failing on any
`Deprecated:` line in the output.

## 3. JIT guidance

- The plugin performs no JIT tuning itself and does not require JIT.
- Recommended production setting: leave `opcache.jit` at its default
  (`off` on most shared hosts, `tracing` where the host enables it). The
  plugin's hot paths (static HTML serve via `advanced-cache.php`, Redis
  object cache, minify/combine) are I/O-bound; JIT yields no measurable win
  and aggressive JIT modes increase memory pressure.
- If a host enables JIT globally, no plugin change is needed — all
  version-gated branches use plain `version_compare()` calls that JIT
  compiles cleanly.

## 4. OPcache guidance

- Recommended production settings (host-level `php.ini`, not set by the plugin):
  `opcache.enable=1`, `opcache.enable_cli=0`,
  `opcache.memory_consumption=128` (raise to 256 on large multisite),
  `opcache.max_accelerated_files=10000`,
  `opcache.validate_timestamps=1` with `opcache.revalidate_freq=2` (set `0`
  + manual flush only on immutable deploy pipelines).
- **Keep `opcache.preload` off by default.** Preloading the plugin's
  conditionally-loaded classes (`advanced-cache.php` drop-in, Redis drop-in,
  Action Scheduler stores) across deploys risks stale-class errors after
  updates; the failure mode is a white screen, which violates the fail-open
  rule. Only enable preload on immutable container images where the preload
  script is regenerated per build.
- After plugin updates, flush OPcache once (host panel or
  `opcache_reset()` via WP-CLI) so the drop-ins recompile; the plugin's own
  cache-clear hooks do not (and must not) reset server OPcache automatically.
- Debugging deprecations: enable `WP_DEBUG` + Query Monitor on staging,
  visit the settings and dashboard tabs, and confirm zero `Deprecated:`
  notices before promoting to production.

## 5. Re-sweep results (issue #1260)

- **Implicitly-nullable signatures (PHP 8.4):** re-swept `includes/`,
  `templates/`, root `*.php`, and `uninstall.php` with a tokenizer-aware
  check (typed `Type $x = null` without `?`/`|null`/`mixed`). True hits:
  **0** — every `= null` default is an explicit `?Type = null`, an untyped
  `$x = null`, or a legal `mixed $x = null` (e.g.
  `CDN::rewrite_url()`/`rewrite_srcset()`, left untouched). The WP
  object-cache drop-in keeps core's untyped signatures by design.
- **Banned calls/usage:** `curl_close(null)`, `E_STRICT`, `mysqli_ping()`,
  and backtick shell execution: **0** hits in plugin code. The backtick
  characters that exist are `strpbrk()` XSS guards and docblock text, not
  execution. Redis `$manager->ping()` calls are unrelated to
  `mysqli_ping()`. Raw `curl_close()`/`curl_multi_close()`/
  `curl_share_close()`/`finfo_close()`/`xml_parser_free()`/`imagedestroy()`
  calls remain only inside the version-gated legacy branches of
  `includes/class-util.php` (fail-open below PHP 8.5); all production call
  sites use the `Util` helpers.
- **Repeatable grep:** the sweep is now pinned by
  `PhpDeprecationHygieneTest::test_plugin_sources_are_free_of_php84_85_banned_patterns()`,
  a token-based source scan (no regex false-positives from comments or
  strings) covering all six patterns above, so PHP 8.5 stays clean on every
  `composer test` run without CI workflow changes.
- **Vendor bumps:** Action Scheduler 4.1.0, `voku/html-min` 5.0.0, and
  `matthiasmullie/minify` 1.3.75 are already at their latest releases — no
  change. `symfony/css-selector` was bumped `v7.4.17` → `v7.4.18`
  (patch-only, inside the existing `^7.4` constraint) with
  `composer.lock` updated. Dev-only `outdated` hits (PHPCS 4.x major,
  PHPStan, phpstan-wordpress) were deliberately left alone: they do not
  ship and a major bump would risk the lint gate.
- **Floors unchanged:** PHP 8.2 floor with 8.3 recommended;
  `WPPO_REQUIRES_PHP`, `wppo_requirements_met()`, and
  `wppo_version_guard()` messaging are untouched. No option or transient
  scope changes (multisite-safe).
- **Still open (needs workflow permissions, not applied here):** extend
  `psalm-wpcs-check.yml` from the single PHP 8.2 job to an 8.2–8.5 matrix
  running `parallel-lint` plus `composer test`, failing on any
  `Deprecated:` line in the output.
