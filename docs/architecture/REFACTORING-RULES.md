# Refactoring Rules — how every campaign PR ships

## Iron rules

1. **ONE responsibility → ONE extraction → tests → verification → merge.** Never
   rewrite a whole class, never bundle namespace migration with extraction, never
   mix refactor with features/perf work.
2. **Queue discipline:** `docs/architecture/refactor-queue.yaml` is source of truth.
   At most ONE item active; at most ONE campaign GitHub issue open. New discoveries
   become new `queued` items, never scope creep in the current PR.
3. **Facade first:** moved public/static behavior keeps a thin proxy in the old class.
4. **`@since NEXT`** on every new class/method (replaced at release). Never invent versions.
5. **Multisite + compat checklist** in every issue: hooks, filters, REST, options, DB,
   multisite, Woo/Elementor/LiteSpeed/CDN/Cloudflare, frontend behavior, uninstall.

## Test-first extraction (risky code)

1. Pin current behavior with regression tests (PHPUnit + Brain Monkey for
   caching/filesystem/REST/security/multisite/Woo paths; Jest for UI).
2. Extract. 3. Green suite. 4. Same verification order as every PR:
   `npm run lint:js` → `composer lint` → `npm test` → `npm run build`
   (+ `composer test` when PHP touched). 5. Stage committed `build/` output.

## Automation (this repo's actual mechanism — verified 2026-09-22)

- There is **no standalone issue-analysis workflow**. The pipeline is:
  issue labeled `autofix-trigger` (or `/fix` comment on a plain issue)
  → `fix-issue` job in `wppo-ai-review.yml` (branch `autofix/issue-N`, PR labeled `autofix`)
  → `review` + `autofix` loop → `autofix:ready` → auto-merge consideration.
- Campaign issues are created **without** `autofix-trigger` so design review happens
  first. The label (or `/fix`) is added only when analysis questions are resolved.
- **Security policy (issue #1564):** every `/fix` comment trigger on `fix-issue`
  (plain issue) and `autofix` (PR comment) must allow only trusted
  `github.event.comment.author_association` values `OWNER`, `MEMBER`, or
  `COLLABORATOR`. Untrusted associations (`NONE`, `CONTRIBUTOR`,
  `FIRST_TIMER`, `FIRST_TIME_CONTRIBUTOR`) must fail the job-level `if` before
  any secret-bearing step runs. Label triggers (`issues.labeled` +
  `autofix-trigger`, `pull_request.labeled` + `autofix`) stay maintainer-controlled
  because applying labels requires triage/write permission.
- **Merge gate:** branch loop idle (all workflows, not just one) + CI clean +
  manual diff review. Never merge mid-loop. Build-asset conflicts: keep `--ours`,
  rebuild, commit, push with lease.
- Watchdog `wppo-agent-rules.md` wins over any loop prompt on conflicts.

## Guardrail baselines (2026-09-22, ratchet down after each extraction)

```text
Main methods      <= 232   (includes/class-main.php, 14639 lines)
Util methods      <= 170   (includes/class-util.php, 8746 lines)
Cache methods     <= 135   (includes/class-cache.php, 7228 lines)
Image_Optimisation methods <= 165  (10967 lines)
Critical_CSS methods       <= 137  (7595 lines)
Largest PHP class <= 14639 lines (Main)
Largest React component   <= 6228 lines (FileOptimization.js)
Dashboard.js      <= 2296 lines
```

A feature PR must not grow these without justification; each extraction PR lowers
the relevant cap. Full inventory: `docs/architecture/class-inventory.json`.

## AI review must flag (future reviews)

SRP/god-class/god-method, DRY, feature→feature internals coupling, new global/static
state, config leakage (raw option-array manipulation outside the owner), infra
leakage (features doing raw FS/network/DB), UI/business coupling in React,
missing regression coverage for behavior-changing extractions.
