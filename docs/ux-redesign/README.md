# Admin UX Overhaul — campaign record

Durable memory. Findings and decisions, not a transcript.

## Objective

Turn the admin SPA from a feature-heavy technical control panel into a clear
administration experience: **Overview / Speed / Media / Data & System / Manage**,
URL-aware navigation, a genuine evidence-based Overview, localized loading
feedback, verified accessibility and responsive behaviour.

## Phase 0 — discovery (complete)

Confirmed by live browser evidence, see `information-architecture.md` for the
full table.

- The active tab was **never in the URL**; sidebar items had no `href`.
- Seven flat tabs; `file_optimisation` holds **96** settings in a **6,320-line**
  component; `Dashboard` had become a junk drawer holding **13** panels.
- Verified already correct, so not "fixed" again: the dirty-form guard (#850)
  and per-button loading state (#849). All twelve UI-audit issues #839–#850 are
  closed and spot-checking found real fixes, not cosmetic closes.

A finding I retracted: "Tools is broken" was **my Playwright selector** hitting
the hidden mobile nav, not a product defect. Re-tested against visible buttons
only — Tools works. No issue filed. Recorded because it is exactly the shape of
false finding this campaign must avoid.

## Baseline

`/var/tmp/ux-campaign/baseline.txt` — settings, drop-ins, cron, plugin state,
captured before any change. The `pwtest` password in `/tmp/pwtest-cred.txt` was
stale; reset for the disposable test account ID 4, new value stored mode-600.
No real user data was touched.

## Phase 1 — information architecture and URL routing (complete)

Five areas, every original screen preserved as a sub-item, URL as the source of
truth, verified live across Back/Forward/refresh/direct/invalid. Two runtime
defects found by the live pass that lint, the build and 1,089 unit tests all
passed; both fixed, and a mount smoke test added so that class of bug cannot
regress silently.

## Decisions

1. **Routing first.** Every later phase assumes a stable section identity.
2. **No router dependency.** The repo has none by design; `pushState` plus
   `popstate` is the right size of solution for one query key.
3. **Reuse the existing primitives.** `LoadingSubmitButton`, `NoticeBanner`,
   `useNotice`, `useUnsavedChanges`, `ConfirmDialog` are all sound — #849 and
   #850 prove they were built correctly.
4. **Group, never delete.** Density is addressed by grouping and disclosure.
5. **Verify in a real browser before claiming anything.** Two blank-admin bugs
   reached a fully green pipeline; only execution caught them.

## Progress

- [x] Phase 0 discovery
- [x] Phase 1 information architecture + URL routing
- [ ] Phase 2 Overview
- [ ] Phase 3 loading/feedback system
- [ ] Phase 4 visual design system
- [ ] Phase 5 accessibility pass
- [ ] Phase 6 live functional validation
- [ ] Phase 7 regression hardening

## Known remaining problems

- A pre-existing console error, `Failed to fetch activities: signal is aborted
  without reason`, fires on navigation. The `aborted` guard exists but does not
  cover the abort reason. Cosmetic; belongs to Phase 3.
- `Dashboard` is still a 13-panel junk drawer. Phase 2 splits it.
- `FileOptimization` is still 6,320 lines with 96 settings. Progressive
  disclosure is Phase 4.
- Screenshot review via the harness image tool is blocked by a path-permission
  constraint; structural assertions are used instead.

## Next task

Phase 2: a genuine Overview that answers "what state is my site in, and what
should I do next", built from existing authoritative endpoints, with no fake
health score.
