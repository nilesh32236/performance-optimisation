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
  strings) covering the original six patterns above (extended to 13 in §6 below), so PHP 8.5 stays clean on every
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

## 6. Full PHP 8.5 deprecation verdicts (issue #1292)

Source:
[`migration85.deprecated`](https://www.php.net/manual/en/migration85.deprecated.php).
Every entry below was grep-verified against `includes/`, `templates/`,
and root `*.php`; the machine-checkable subset is pinned by
`PhpDeprecationHygieneTest::test_plugin_sources_are_free_of_php84_85_banned_patterns()`
(13 pattern classes). Floors unchanged: PHP 8.2 minimum, WP 6.2+;
`Requires PHP`, `WPPO_REQUIRES_PHP`, and `composer.json` were not touched.

- **Fixed by #1292:**
  - `Reflection::{Method,Property}::setAccessible()` — removed everywhere
    (one production call in `Main::read_private_module_store()`, about 320
    in `tests/php/` — about 322 total across 70+ files; source of truth is
    `PhpDeprecationHygieneTest::test_plugin_sources_are_free_of_php84_85_banned_patterns()`
    or `grep -rn --include='*.php' 'setAccessible' includes/ templates/ tests/php/`).
    The calls were no-ops since PHP 8.1 and the plugin
    requires PHP 8.2+, so deletion is behavior-preserving; reflection
    reads/invokes work without them on every supported runtime.
  - Raw `imagedestroy()` in `ImageAvifPictureTest.php` fixture cleanup —
    now released via `Util::destroy_gd_image()`.
  - New scanner patterns: `setAccessible`, non-canonical casts,
    `mysqli_execute`, `socket_set_timeout`, `$http_response_header`,
    `DATE_RFC7231`/`::RFC7231`, `__sleep`/`__wakeup` definitions —
    with positive + negative synthetic fixtures.
- **Already guarded (Util helpers, fail-open legacy path below 8.5):**
  `curl_close()`, `curl_share_close()`, `finfo_close()`,
  `xml_parser_free()`, `imagedestroy()`. Note: upstream PHP 8.5
  deprecates `curl_close()` + `curl_share_close()` but **not**
  `curl_multi_close()`; the existing `close_curl_multi_handle()`
  over-gating is harmless (fail-open either way) and is kept for
  symmetry.
- **Scanner-pinned, zero hits in shipped code:** non-canonical casts
  (`(boolean)`/`(integer)`/`(double)`/`(binary)`), backtick operator,
  `E_STRICT`, `mysqli_ping()`, `mysqli_execute()`,
  `socket_set_timeout()`, `$http_response_header`, `DATE_RFC7231`,
  `__sleep()`/`__wakeup()` definitions, implicitly-nullable signatures.
  (`chr( 10 )` newline joins in `class-image-optimisation.php` are
  in-range single bytes — not deprecated.)
- **Audited, not applicable (zero hits, no scanner rule needed):**
  output-handler echo, `__debugInfo()` returning null, closure-binding
  edge cases, `null` array offsets, non-numeric string increment,
  `finfo_buffer()` context arg, `SplObjectStorage::contains/attach/detach`,
  `ArrayObject`/`ArrayIterator` with objects, `spl_autoload_unregister`
  with `spl_autoload_call`, `readdir()`/`rewinddir()`/`closedir(null)`,
  out-of-range `chr()` / multi-byte `ord()`, `case ... ;` terminators,
  `PDO::*` driver constants/methods, `MHASH_*`, `intl.error_level`,
  LDAP wallet calls, `openssl_pkey_derive()` `key_length`,
  `report_memleaks` / `register_argc_argv` ini usage, constant
  redeclaration, `FILTER_DEFAULT`, `Pdo\Pgsql` transaction-state
  constants, `ReflectionParameter::allowsNull()`,
  `ReflectionClass::getConstant()` on missing constants, and
  `ReflectionProperty::getDefaultValue()` without defaults.
- **OPcache/JIT guidance:** unchanged — see §3 (JIT) and §4 (OPcache)
  above. No plugin-level tuning was added; the guidance stays a docs-only
  page so hosts can set `php.ini` without the plugin touching server
  state (fail-open).

## 7. PHP 8.5 header hygiene + OPcache/JIT observability (issue #1309)

- **`$http_response_header` (PHP 8.5):** zero production reads (scanner-pinned,
  §6). The sanctioned API is now `Util::get_last_response_headers()`
  (`includes/class-util.php`, `@since NEXT`): prefers
  `http_get_last_response_headers()` behind `function_exists`, falls back to
  the legacy global path via `isset( $GLOBALS[ 'http_response_header' ] )`
  (always initialized, `is_array`-guarded, string-filtered), and fail-opens
  to `array()` on any probe failure. No raw close-call changes were needed —
  `close_curl_handle()` and siblings already cover every teardown call site
  (`class-telemetry.php`, `class-litespeed-crawler.php`,
  `class-img-converter.php`).
- **System Info OPcache/JIT rows:** `System_Info::get_opcache()`
  (`includes/class-system-info.php`) now reports `opcache_enabled`
  (`opcache.enable`), `opcache_enable_cli` (`opcache.enable_cli`),
  `jit_enabled` + `jit_mode` (`opcache.jit`, `opcache.jit_buffer_size`)
  alongside the existing `status/detail/memory_usage/interned_strings/
  hit_rate/cache_full` rows. Every ini read is guarded with
  `function_exists( 'ini_get' )`; JIT rows additionally require PHP 8.0+
  via `defined( 'PHP_VERSION' )` + `version_compare()`. Unreadable values
  render translated `Not available` and never block the screen (`try/catch`
  fail-open around the whole probe).
- **Conditional loading + multisite:** the SPA already fetches System Info
  on demand ("Load System Info" button, no frontend cost); the probe itself
  is now cached briefly (5-minute per-site transient via
  `Util::transient_key( 'wppo_sysinfo_opcache' )`), so refreshes never
   hammer `opcache_get_status()` and multisite transients cannot leak
   across sites. Public-site cost is zero (admin-only screen); the admin
   bundle grows negligibly (4 label strings, no extra requests) with one
   per-site transient read per System Info load (5-min TTL).
