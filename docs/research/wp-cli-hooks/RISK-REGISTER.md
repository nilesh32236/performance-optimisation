# RISK-REGISTER.md — Likelihood/Impact/Mitigation per phase

**Base:** `fix/audit-2026-08-28` `68a2f66a` | **Branch:** `fix/cli-phase-*` | **Mode:** research-only

| Risk | Likelihood | Impact | Mitigation | Phase | Owner |
|------|------------|--------|------------|-------|-------|
| Hook conflicts: new `wppo_*` collides with theme/plugin | Low | Medium | Prefix `wppo_` unique + `grep -r wppo_ --include=*.php wp-content` pre-merge + `_deprecated_hook` alias if rename | P0/P1 hooks | Hook Arch |
| BC break: existing `wppo_*` docs change args | Low | High | `@since NEXT` new hooks only, never change 42 existing `docs/hooks.md:1-439` signatures; new filters return `WP_Error` veto, not silent | All | Compat |
| Performance: filter per request adds overhead | Low | Low | P0 veto filters early return before `ob_start`/`DELETE` (saves work), measured 0.02 ms/filter, gated `apply_filters` only when needed per `PERF-RESEARCH:6` | P0/P1 | Perf |
| Multisite pagination `get_sites` memory | Medium | Medium | `get_sites(['number'=>100,'paged'=>...])` paginated `switch_to_blog` + `try/finally restore_current_blog()` per `uninstall.php:193-217` canonical, not `get_sites(['number'=>-1])` | P0 --network | Compat |
| WP_Filesystem not initialized on CLI | Medium | Medium | `Util::init_filesystem:322` lazy only when `is_admin()`||`WP_CLI` per `PERF-RESEARCH`, `global $wp_filesystem; if(!$wp_filesystem) WP_CLI::error` | P0 export/import | Perf |
| Transient prefix collision on shared object cache | Low | Medium | `Util::transient_key:781` `{blog_id}_` prefix + `blog_prefix` `templates/object-cache.php:532` | P0 cache veto | Compat |
| WP_CLI missing (non-WP-CLI execution) | Low | Low | `if(!class_exists('WP_CLI')) return;` + `if(defined('WP_CLI') && WP_CLI)` + `@when after_wp_load` already `class-main.php:472` | All CLI | Compat |
| Hosting variance LiteSpeed/Apache/Nginx `X-LiteSpeed-Purge` | Low | Low | `class-litespeed-integration.php:221` `wppo_litespeed_is_litespeed` filter + `class-server-rules.php:34` `get_server_type` already | P0 cache | Compat |
| Buffer 6-stage hook coupling | High | High | **Rejected** per `ADVERSARIAL G-04` — keep `wppo_cache_page_html:1661` only, no `wppo_buffer_stage` | P2 deferred | Arch |
| Progress bar on piped CI | Low | Low | Gate `make_progress_bar` only when `count>10` + `STDOUT` is tty + `! Utils\get_flag_value('format')` + `NoOp` fallback `WP-CLI-RESEARCH:210` | P2 progress | CLI UX |
| Dry-run mutation leak | Medium | High | `Utils\get_flag_value('dry-run')` first line → `if($dry_run){ log JSON `would_*`; return; }` before `Cache::clear_cache`/`DELETE`/`OPTIMIZE` | P0 dry-run | Tests |
| God class Main 3053 refactor scope creep | High | Medium | **Deferred** per `ARCH-RESEARCH` + `ADVERSARIAL` — PSR-4/PR-D 2.0, keep `@when after_wp_load` `classmap` vs `AGENTS.md` manual `require_once` | P3 | Arch |
| Documentation drift `docs/hooks.md` 42→69 | Medium | Low | Generation via `grep -R wppo_ --include=*.php | docs/hooks.md` + `@since NEXT` per `AGENTS.md:79` | Docs | Tests |
| Test harness `WP_CLI` stub missing `Formatter`/`Utils` | Low | Low | Add `tests/php/stubs/wp-cli.php` `WP_CLI\Utils\format_items`/`Formatter` + `ExitException` per `TEST-PLAN:648` | Tests | Tests |

