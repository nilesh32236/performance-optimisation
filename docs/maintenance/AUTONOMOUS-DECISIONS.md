# Autonomous Decisions Log

## Duplicate Handling 2026-08-31
- PR 744 vs 752 vs 762 all fix RedisSentinel constructor positional args. Choose 744 as canonical (strict types + validation + WP_DEBUG error_log). 752 and 762 closed as duplicates with reference to 744. Decision preserves most robust fix (host/port validation, strict casts, debug log).

## Security 2026-08-31
- 747 optimize_table regex /^[A-Za-z0-9_]+$/ + isset is_string validated vs allowlist - approved, allowlist too restrictive for wp core tables (would break users, woocommerce_sessions, etc.). Regex ensures alphanumeric+underscore only, prevents property injection and indirect SQLi.

## Supersized PR 765 Deferral 2026-08-31
- 212 files, 25k additions, exceeds 100-file review limit, conflicting after 8 merges. Contains valuable CLI/hooks/perf work but bundled with AUDIT docs (52 files) and build artifacts. Closed with branch preserved at origin/fix/audit-2026-08-28 for split into 5 PRs (<100 files each): CLI Phase, Hooks Phase, Perf P2-P5, H-fixes, Option B redesign pending #709. Rationale: bypasses 95% confidence gate and review limit, would block CI.

## WPCS #766 Handling 2026-08-31
- 31 WPCS issues from 746/751 merges fixed via phpcbf (23 auto) + manual (6): cache depth docs, used-css spread ReplacementsWrongNumber ignore, count in loop DisallowSizeFunctionsInLoops, cron interpolated placeholders disable/enable + UnfinishedPrepare. PR 767 created, verified 0 errors, npm test fixed (AutoloadedOptions 34/34).

## WP Monitor 754-758 Deferral 2026-08-31
- Issues 754 (speculation rules Core delegation), 755 (fetchpriority high/low), 756 (wp_cache_supports guard), 757 (wp_get_loading_optimization_attributes + auto-sizes), 758 (Abilities API) are valid enhancements for WP 6.8-6.9+ with low-medium risk, additive, backward-compatible via function_exists guards.
- **Decision: DEFERRED to next sprint** — not blockers for current WPCS/CI gate. Rationale: each requires new Core API integration, version gating (`function_exists('wp_get_speculation_rules')`, `wp_should_output_buffer_template_for_enhancement`, `wp_cache_supports`, `wp_get_loading_optimization_attributes`, `wp_register_ability`), and manual viewport/performance testing not available in current CI (needs WP 6.9+ environment, mobile/desktop groups, Redis hit rates). Will be split into 5 focused PRs after 767 merges, each <100 files, with @since 2.0.0 and docs/hooks.md updates. Marked analysis:ready kept. No regression if deferred — current plugin still works on <6.9 with legacy fallback.

## Remaining Marketing/Design Issues Deferral 2026-08-31
- 709 (design chooser 3 proposals with Native/Premium/Dense), 708 (LS-904 WP 7.x readiness), 707 (LS-903 N-features), 646 (v2.0.0 meta), 369 (banner/icon), 368 (screenshots) — deferred as non-blocking enhancements/docs, require product/design input, no code change in this autonomous run. Documented to avoid silent skip. Each has enhancement/documentation label, not bug.

## Conflict Resolution 749 — Palette Aria
- Palette aria PR had 5-file conflict on .jules/palette.md + build files after master advanced 7 merges. Resolved by cherry-picking EdgeCachePanel.js aria-describedby (5 ids), merging palette.md chronologically (both 08-29 Edge Cache ARIA + 08-31 Action Buttons), and rebuilding via wp-scripts (index + tabs). Build maps kept from HEAD to avoid deletion noise (webpack 5.109.2 doesn't emit maps by default, but HEAD had maps — keeping avoids 36455 deletion noise). Verified CI passed.

## AutoloadedOptions Test Fix 2026-08-31
- Test failure after 746 (migrated to useNotice) showed "No autoloaded options found." even on error (notice). Fixed by `if (notice) body=null else if (empty && !loading) emptyState else if (>0) list` — preserves UX: error notice shown, empty state hidden. Now 34/34 pass.

## .gitignore Screenshot Helpers
- Added take_screenshot*.js + take_wizard_390.js to .gitignore (local dev artifacts from design work, not part of plugin). Not committed to PR 767 but will be included in maintenance docs PR.


### 2026-08-31 14:30 UTC — E1 CLI Extraction Decisions
- **Synopsis `[<action>]` default:** KEEP — fixes doc-code drift (master `<action>` vs code `??'clear'`), WP-CLI Handbook, no BC break
- **--format=json JSON-only:** KEEP — wp_json_encode fallback, valid JSON, jq-ready, reject non-json → json, no table/csv/yaml/Spyc per FINAL-ADVERSARIAL-REVIEW (REJECT table/csv)
- **--yes for type=all:** KEEP — `WP_CLI\Utils::get_flag_value` + tty check + confirm, non-TTY proceeds, WP-CLI convention, safety for destructive all
- **--dry-run for cleanup/optimize:** KEEP — reuse get_counts, early return before DELETE/OPTIMIZE, warning, standard `wp search-replace --dry-run` pattern, REJECT blind --yes everywhere
- **Allowlist optimize --tables:** KEEP — TABLE_MAP unique + warning skip, defence-in-depth vs regex, REJECT unbounded
- **Extra types trashed_comments/unattached/oembed:** KEEP — backend already has clean_* methods, alias support, help now truthful 9+all, REJECT if synopsis not updated
- **Hook per-type:** KEEP — additive, already clean_all had all, now per-type
- **Delegate get_default_settings:** KEEP critical — fixes 7-tab drift, blog-keyed memo (switch_blog safe), REJECT stale inline array
- **Object-cache ALLOWED_KEYS 12:** KEEP — converge CLI 6 + REST 10, parity with UI/API
- **REJECTED:** --confirm alias, table/csv/yaml, --network custom get_sites (use --url), --batch-size/progress (complexity), research docs not shipped, UX/performance unrelated (E2-E5 separate)

## Recovery Decisions 2026-09-25
- **#1563 chronology:** Run `35958652063` passed installation and failed research because the run-time `OPENCODE_MODEL` was `opencode/space-bunny-free` and the provider returned `UnknownError`. Run `36140868928` failed before research because the pinned installer unnecessarily queried the GitHub Releases API and replaced the transport error with `API error`. The current repository variable is explicitly `opencode/muse-spark-1.3-contributor-free`; no silent model fallback was added.
- **Installer decision:** Keep the monitor fail-hard contract. For the four direct raw-CLI callers, require stable pinned `vMAJOR.MINOR.PATCH`, use the direct release URL, verify the committed x64/arm64 SHA-256 map, inspect one regular archive member, validate the staged binary before atomic replacement, scrub installer credentials, disable persisted checkout credentials for the raw OpenCode job, and publish the verified install directory through `GITHUB_PATH`. Tri-merge conflict resolution additionally uses an ephemeral authenticated fetch/push URL, removes `FETCH_HEAD` before OpenCode, and runs the model with a minimal environment; no repository token is stored in checkout configuration. Do not add placeholder research or accept malformed findings.
- **PR triage:** #1647 is a duplicate of merged #1648. #1645 is a no-op because the two requests already start concurrently. #1629 and #1611 are stale/conflicting bundles; #1649 changes fail-open error propagation on integer overflow and must not merge as-is. These classifications are recorded for maintainer closure; no human approval was inferred.
- **Issue triage:** #1628 remains a partial maintainability backlog, not one broad implementation item. #1410, #910, and #911 remain deferred or partial as documented in their issue analyses. P3-024 remains queued and must be narrowed to `AI_Model_Store` ownership before activation.
- **Model safety:** The current model-intelligence scorer still needs a focused free-only admission and executable-evidence gate before live challenger promotion is enabled. No production routing change is part of this installer repair.
- **Residual automation boundary:** Action-based `opencode-ai-reviewer@main` consumers remain outside this local installer patch. Their floating installer policy requires a separate follow-up and is not silently changed here.
