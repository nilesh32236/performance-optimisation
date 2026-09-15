# Audit: Maintainability & Modularity

You are auditing a WordPress plugin (PHP 8.2+, `includes/`, manual `require_once` loading, no PSR-4) and its React SPA (`src/`, pure `useState`, no state library) for long-term maintainability. Flag code that works today but will rot: duplication that forces multi-spot edits, oversized units, and missing seams.

## What to Check

### Duplicated Logic (DRY)
- Same or near-same logic in 2+ places that must change together (validation, sanitization, URL building, settings reads, notice/error patterns)
- Copy-pasted blocks across `includes/class-*.php` that should be a shared `Util::` helper or a small dedicated class
- Repeated JSX patterns across `src/components/` that should be a common component in `src/components/common/`
- Duplicated WP stubs/mocks across `tests/php/*Test.php` that belong in the `WPPO_Test_Bootstrap` trait or a shared helper
- When flagging, name ALL locations involved so the fix can cover them in one change

### Oversized Units (Split Candidates)
- PHP methods longer than ~80 lines or classes longer than ~800 lines — propose a split point (extract method / extract class), not just "too long"
- React components longer than ~400 lines — propose which section becomes a child component
- Functions with 5+ parameters — propose a parameter object or split
- Deeply nested conditionals (3+ levels) — propose guard clauses or extraction

### Missing Seams & Coupling
- Direct instantiation (`new Class`) of a collaborator where a filter, factory, or constructor argument would allow testing/extension (see `wppo_*` filter conventions in `docs/hooks.md`)
- Cross-tab or cross-module state shared via globals instead of `apiCall()` + `wppoSettings`
- New static memo caches in `Util` or `Cache` without a corresponding reset in the test bootstrap trait (causes order-dependent test pollution)
- Test classes that shadow the bootstrap trait's `setUp()` but skip its resets (`reset_cached_home_urls()`, `clear_settings_cache()`)

### Dead Weight
- Unused helpers, unreachable branches, filters/actions documented in `docs/hooks.md` but never applied
- Test files that no longer assert anything meaningful (all-skipped, no assertions)

## What NOT to Flag
- Intentional duplication for fail-open safety where the comment says so (e.g. duplicated guards across hot paths)
- WordPress-mandated boilerplate (plugin header, `require_once` list in `Main::includes()`)
- Cosmetic refactors with no maintenance payoff — every finding must name the future bug it prevents

## Output Format

Write findings to the output file in JSON Lines format:

```jsonl
{"type":"summary","text":"Audited {target_dir}. Found X issues."}
{"type":"issue","severity":"critical|important|minor","file":"relative/path","line":42,"message":"What the issue is","suggestion":"How to fix it","inline":false}
```

## Severity Guide

- **critical**: Duplicated security/sanitization logic that can drift apart (nonce, capability, escaping, SQL preparation)
- **important**: Duplicated business logic in 3+ places, god class/method with a clear split point, untestable direct instantiation on a hot path, test isolation leak affecting other suites
- **minor**: 2-spot duplication with an obvious helper home, long-but-cohesive unit, missing shared test helper
