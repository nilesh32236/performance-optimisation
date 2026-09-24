# Refactoring Rules: Phase 3

These rules govern architecture pull requests. `wppo-agent-rules.md` wins when a workflow or loop conflicts with this document.

## Core rules

1. Keep one active queue item, one campaign issue, and one pull request.
2. Pin risky behavior before moving it.
3. Extract one responsibility with one target owner.
4. Keep public and hook-visible compatibility unless removal is the approved scope.
5. Run the applicable verification gate and inspect the graph delta.
6. Merge only after every workflow loop is idle, CI is clean, and a human reviews the diff.
7. Verify installed WordPress after every merge before starting the next item.

Records marked `type: epic` or `status: planning` are architecture buckets, not executable items. Split each into one responsibility and one issue before it can become the active queue item.

A directory move, method extraction, bug fix, and facade removal do not belong in one item unless the target owner cannot be proven without the combined change.

## Issue gate

- There is **no standalone issue-analysis workflow**. The pipeline is:
  issue labeled `autofix-trigger` (or trusted `/fix` comment on a plain issue)
  → `fix-issue` job in `wppo-ai-review.yml` (branch `autofix/issue-N`, PR labeled `autofix`)
  → `review` + `autofix` loop → `autofix:ready` → auto-merge consideration.
- Campaign issues are created **without** `autofix-trigger` so design review happens
  first. The label (or trusted `/fix`) is added only when analysis questions are resolved.
- **Security policy (issue #1564):** every `/fix` comment trigger on `fix-issue`
  (plain issue) and `autofix` (PR comment) allows only trusted
  `github.event.comment.author_association` values `OWNER`, `MEMBER`, or
  `COLLABORATOR`; the job-level `if` rejects all other associations before any
  secret-bearing step runs. Label triggers (`issues.labeled` + `autofix-trigger`,
  `pull_request.labeled` + `autofix`) remain maintainer-controlled because applying
  labels requires triage/write permission.
- **Merge gate:** branch loop idle (all workflows, not just one) + CI clean +
  manual diff review. Never merge mid-loop. Build-asset conflicts: keep `--ours`,
  rebuild, commit, push with lease.
- Watchdog `wppo-agent-rules.md` wins over any loop prompt on conflicts.

Every architecture issue records:

- current owner and evidence;
- current responsibilities and dependencies;
- target owner and dependency direction;
- exact source scope;
- non-goals;
- compatibility constraints;
- behavior and regression tests;
- WPCS and static-analysis impact;
- WordPress and runtime verification;
- rollback strategy;
- expected graph or ownership metric change.

“Refactor Main,” “split Util,” and “reduce coupling” fail this gate without method-level and file-level scope.

## Test-first extraction

Use this sequence for risky behavior:

```text
characterize current behavior
→ add regression test
→ extract owner
→ run targeted tests
→ run full applicable suite
→ inspect graph and inventory delta
→ inspect the complete diff
→ merge through CI
→ verify installed WordPress
```

Pin these contracts when relevant:

- hook names, priorities, accepted arguments, and callback identity;
- filter return values;
- cache keys, TTL, stampede locks, and atomic writes;
- output buffering and frontend markup;
- REST slugs, permissions, schemas, and response bodies;
- CLI commands, output, and exit codes;
- cron and Action Scheduler ownership;
- multisite blog switching;
- LiteSpeed coexistence, headers, nonce, and ESI behavior;
- Redis drop-in loading and namespacing;
- React settings snapshots, dirty state, aborts, and visible output.

## Compatibility and bridges

A moved public or static API keeps a same-signature proxy while runtime callers remain. Record each proxy as one of:

- required compatibility;
- required lifecycle;
- temporary migration;
- cycle-producing and removable;
- protected.

The issue names the removal condition. Temporary bridges may increase graph edges for one item. A later item must delete them.

Do not remove a facade because its implementation moved. Prove caller migration, executable reference removal, test coverage, documentation impact, and release impact first.

## Dependency direction

Prefer:

```text
Bootstrap/Core
→ application coordination
→ domain services
→ infrastructure and compatibility
→ external systems
```

New edges from infrastructure to features, domain to Admin, or services to `Main` need an explicit reason in the issue. A class-name string in a guard does not hide a dependency; the tokenizer records it as compatibility evidence.

## State rules

Every changed state property needs a lifecycle label:

- request memo;
- cross-request state;
- compatibility state;
- singleton state;
- mutable global.

For request and site-sensitive memos, prove reset and blog-switch behavior. Prefer instance state or a value object when lifecycle permits it. Keep static state when WordPress callback identity or cross-request behavior requires it, and document that constraint.

## DRY rules

Consolidate behavior only when the semantics match. Inspect:

- settings validation and writes;
- permission checks;
- sanitization and URL normalization;
- cache-key policy;
- response envelopes;
- database dispatch;
- feature detection;
- filesystem containment;
- hook registration;
- polling and settings-response mapping.

Do not create one generic helper for behavior that merely looks similar. One owner and a small API beat a configurable utility.

## SOLID rules

- Apply SRP to independent reasons to change, not file size.
- Use OCP at real provider and adapter seams.
- Apply LSP only to shared contracts with multiple implementations.
- Use interfaces for external seams or genuine shared behavior.
- Invert filesystem, HTTP, database, time, randomness, and provider dependencies where it improves tests or adapters.

Do not add a DI container, interface per class, DTO per array, inheritance layer, router, or store.

## WPCS and static analysis

Every PHP change must pass:

```sh
php vendor/bin/phpcs
```

Use `phpcbf` for mechanical fixes. Keep every new suppression local and justified.

Run `phpstan` when the repository configuration and local resources support it. The current baseline is not green, so record the command, result, and reviewed new errors. Never hide new errors inside broad ignore patterns.

## Architecture evidence

After source changes:

```sh
php scripts/generate-class-inventory.php
php scripts/generate-class-inventory.php --check
```

Review:

- class lines, methods, and methods at least 80 lines;
- fan-in, fan-out, and reference evidence;
- runtime SCC size;
- compatibility edges and compatibility SCC size;
- boundary violations;
- bridge candidates;
- static properties;
- duplicate candidates;
- `Util` and `Main` metrics;
- cross-domain and feature-to-feature edges.

Metrics are review signals. A line-count reduction does not prove better ownership. A graph increase needs a documented bridge and removal path.

## Pull-request verification

Run applicable stages in the watchdog order:

```sh
npm run lint:js
php vendor/bin/phpcs
npm test
php vendor/bin/phpunit
npm run build
```

Add the architecture drift check. The CI workflow runs the same command.

Before every commit:

```sh
git status --short
git diff --stat
git diff
```

Exclude `vendor/`, `node_modules/`, worktree symlinks, scratch output, reports, and unrelated local directories. Never stage `dsh-project-notes/` or other harness-local state.

## Merge gate

Do not merge until all of these hold:

- latest pull-request head matches the reviewed commit;
- no workflow run is queued or active for the branch;
- review comments are resolved;
- CI is clean;
- local lint, tests, build, and graph checks pass;
- PHPCS passes with zero errors;
- a human reviewed the full diff;
- scope matches the issue;
- at least one architecture metric improved or the pull request documents a necessary bridge.

## Post-merge gate

Immediately after merge:

1. Fetch and fast-forward `master`.
2. Confirm the working tree has no unexpected tracked changes.
3. Refresh the installed plugin and owned drop-ins from the merged source.
4. Run `wp wppo verify`.
5. Run runtime smoke checks for touched surfaces.
6. Regenerate inventory and graph.
7. Compare the baseline metrics.
8. Update the queue and active baseline.
9. Re-audit the item before starting the next one.

A new warning, route-count change, cron leak, console error, or graph regression blocks progression.

## Current numeric ratchet

The current Phase 3 baseline records the starting metrics in `ARCHITECTURE-BASELINE.md`.

| Metric | Current cap or baseline | Rule |
|---|---:|---|
| `Main` lines / methods | 10,790 / 235 | Do not grow without a documented extraction path |
| `Main` methods at least 80 lines | 20 | Target down |
| `Main` fan-out / feature dependencies | 37 / 17 | Reduce delegated edges in P3-014/P3-016 |
| `Util` lines / methods | 5,006 / 156 | Target down through caller migration |
| `Util` fan-in / incoming evidence | 58 / 1,139 | Target down |
| `Cache` lines / methods | 5,077 / 167 | Target down with cohesive extractions |
| `Image_Optimisation` lines / methods | 8,999 / 183 | Target down with media or state owners |
| `Critical_CSS` lines / methods | 6,950 / 138 | Target down with generation owners |
| Largest runtime SCC | 68 nodes | Target down |
| Compatibility-classified edges | 196 | Explain and reduce through explicit compatibility ownership |
| Compatibility SCCs / largest | 1 / 21 nodes | Reduce; retain only documented stable adapters |
| Boundary violations | 16 | Target zero or protected exception |
| Bridge candidates | 234 | Target down |
| Static properties / owners | 143 / 32 | Classify, then reduce or document |
| `FileOptimization.js` | 6,114 | Target down one card at a time |
| `Dashboard.js` | 2,168 | Target down one card or hook at a time |

A pull request may exceed a cap only when the issue proves the increase is temporary and names the removal item.

## Automation

The repository uses:

```text
issue with autofix-trigger
→ fix-issue branch and PR
→ review/autofix loop
→ local and CI gates
→ merge
→ installed WordPress verification
```

Only the current issue receives the trigger label. Do not create the full backlog in GitHub. Keep `refactor-queue.yaml` as the local planning record and GitHub as the execution record.

## Periodic audit

After every three to five architecture pull requests, review:

- `Main`, `Util`, `Cache`, images, CSS, Rest, CLI, insight, and React;
- graph SCC and boundary deltas;
- static-state classifications;
- duplicate candidates;
- compatibility proxies;
- WordPress and multisite verification;
- whether complexity fell rather than merely moving files.
