# Admin UX Overhaul — campaign record

Durable memory. Findings and decisions, not a transcript.

## Objective

Turn the admin SPA from a feature-heavy technical control panel into a clear
administration experience: **Overview / Speed / Media / Data & System / Manage**,
URL-aware navigation, a genuine evidence-based Overview, localized loading
feedback, verified accessibility and responsive behaviour.

## Phase 0 — discovery (complete)

- The active tab was **never in the URL**; sidebar items had no `href`. Proven by
  walking all seven tabs and watching `location.href` never change.
- Seven flat tabs; `file_optimisation` holds **96** settings in a **6,320-line**
  component; `Dashboard` had become a junk drawer holding **13** panels.
- Verified already correct, so not "fixed" again: the dirty-form guard (#850)
  and per-button loading state (#849).

## Phase 1 — information architecture and URL routing

Shipped in #1672, then corrected. See `information-architecture.md` and
`defects-found-and-fixed.md`.

## Phase 2 — Overview (in progress)

`src/lib/overviewStatus.js` derives every Overview fact from data the plugin
already exposes, as a pure module so the rules are directly testable.

**No score.** A composite "92/100" would be decoration: every input is already
weighted by something other than a made-up formula, and "enabled" says nothing
about "working". The model reports discrete states — healthy, attention,
not-configured, unavailable, unknown — and never claims health without evidence.

Two honesty defects were found by its own tests and fixed:

1. the object cache reported **healthy** when reachability was never returned;
2. a site with a working cache and no object cache was reported
   **not-configured** rather than healthy, so a correctly configured minimal
   site looked unconfigured.

A third finding was in my own test: the "no numeric score" assertion
destructured `{ rows, overall }` and then serialised only that subset, so a
`score` property on the model was invisible to it. The assertion now serialises
the whole model and rejects any numeric leaf. Repairing it also exposed an
infinite recursion in the walker — `Object.values('a')` yields `['a']` forever.

## Decisions

1. **Routing first.** Every later phase assumes a stable section identity.
2. **No router dependency.** `pushState` + `popstate`, as the repo has none.
3. **Reuse existing primitives.** #849 and #850 prove they were built correctly.
4. **Group, never delete.**
5. **Verify in a real browser.** Two blank-admin bugs and three critical
   navigation bugs reached a green pipeline; only execution and independent
   review caught them.
6. **Never merge before the independent review returns.** I broke this once: I
   merged #1672 while its review was still running, and it landed with three
   critical defects. See `defects-found-and-fixed.md`.

## Progress

- [x] Phase 0 discovery
- [x] Phase 1 information architecture + URL routing (merged `d60557ec`)
- [ ] Phase 2 Overview
- [ ] Phase 3 loading/feedback system
- [ ] Phase 4 visual design system
- [ ] Phase 5 accessibility pass
- [ ] Phase 6 live functional validation
- [ ] Phase 7 regression hardening

## Known remaining problems

- A pre-existing console error, `Failed to fetch activities: signal is aborted
  without reason`, fires on navigation. The `aborted` guard does not cover the
  abort reason. Cosmetic; Phase 3.
- `Dashboard` is still a 13-panel junk drawer. Phase 2.
- `FileOptimization` is still 6,320 lines with 96 settings. Phase 4.
- `.wppo-section__title` font-weight is being overridden by an existing more
  specific heading rule; the token set applies, the weight does not. Cosmetic,
  Phase 4.
- Screenshot review via the harness image tool is blocked by a path-permission
  constraint; structural assertions are used instead.

## Next task

Phase 2: the Overview components that render `overviewStatus`, built over
existing endpoints with no new expensive calls.


## Phase 2 — the Overview (in review, PR #1675)

The Overview area had no page of its own. It rendered the old Dashboard, a
13-panel accumulation of audits, charts and diagnostics — which answers "what
does this plugin measure?", not "what state is my site in?".

The new Overview adds `src/lib/overviewStatus.js`, a **pure** status model (no
fetching, no React) so the rules deciding what the page *claims* are directly
testable and cannot drift from what is rendered. The Dashboard keeps all thirteen
panels as the area's second sub-item, "All diagnostics" — relocated, not
reduced.

### There is no health score, deliberately

A composite "92/100" would be decoration. Every input is already weighted by
something other than a made-up formula — PageSpeed weights LCP far above TTFB —
and "enabled" says nothing about "working". The model reports five discrete
states and never claims health without evidence: a feature that is **off** reads
"Not set up", a value the backend **never reported** reads "Unknown", a **failed
request** reads "Unavailable".

### What the reviews caught that I could not

The first review found four ways to make the deployed page lie, each reproduced
by intercepting REST responses:

| Payload | Rendered | Now |
|---|---|---|
| `enabled: "false"`, `redis_reachable: true` | **"Working"** | Not set up |
| `bypassed` / `circuit_open: true` | **"Working"** | Needs attention |
| `{success:false, code, data:{status:429}}` | **"Not set up"** | Unavailable |
| `{lcp:null, cls:null, inp:null}` | 3× **"good at 0 ms"** | Unmeasured |

The first of these was the exact bug my own commit message claimed to have
caught. `triState()` was applied to `cache_enabled` and never to the
neighbouring `enabled` field, so the string `"false"` read as true and a
*disabled* object cache was reported as working.

The vitals feature was also **entirely dead**: `web_vitals_trends` returns
`trends` as an object keyed by `<url-hash>_<strategy>`, not the flat array I
assumed, so zero vitals rows ever rendered.

### The lesson worth keeping

A second green suite covered code that could not work. `deriveCacheStatus` read
`stats.cacheStats` from an object while `buildStatusModel` passed a plain
string, so production read `undefined` — and every test passed, because they
all used the object shape production never took. It surfaced only because live
behaviour contradicted a passing test, and I diffed the minified bundle against
the source to locate it.

The fix was not "write more tests". It was: **a test that does not exercise the
shape production actually uses proves nothing about it.**

This is now the third time this campaign that a green suite hid broken code —
after `useSectionRoute`'s named-vs-default export, and the `item.icon` render
crash. The pattern is consistent enough to name: *a test asserts on what the
code was written to accept, not on what it is actually given.*

### Gate honesty

I also reported "ESLint 0 errors" from a **stale summary line earlier in the
same command block**. CI failed on 15 real errors, one of which was a test with
no assertions at all. A gate result is only evidence if it came from the run
being reported.

### Verified live, against independent ground truth

| Row | State | Checked with |
|---|---|---|
| Page cache | Working — "14 MB of cached pages stored" | `Cache::get_cache_stats()` |
| Object cache | Working — server reachable | WP-CLI: drop-in present, `redis_reachable=true` |
| Compatibility | Working — WP 7.1.2 / PHP 8.3 | `wp wppo system-info --format=json` |
| Loading (LCP) | Working — 505 ms | stored real-user history |

Nothing here is asserted from the page itself; each row was checked against a
source the page did not derive from.

## Round 24 — the seventh review, and a lesson about deploying

### The blocker a review found

A hanging `web_vitals_trends` froze the entire Overview **for ever**. `setLoading(false)`
existed in exactly one place — the vitals `.finally()` — and `apiCall` has no request
timeout. Measured live at 5s, 20s and 45s: **0 status rows, 0 "Try again" buttons**,
while `system_info` and `object_cache` had both already answered.

The code's own comment claimed vitals "never block the page". Absence *was* handled;
**non-settlement was not**, so the one source declared optional was the only one that
could block — and it blocked everything. `SiteStatusCard` returns the loading branch
before the retry affordance, so there was no recourse.

Loading now means "the required sources have settled", plus a backstop that records
anything still outstanding as an error so the retry can appear. The comment's claim is
now true. Verified live: with the request hung, all three required rows render and the
spinner clears within 5s.

### Five unpinned guards

Each was reported as surviving its whole suite. All five now fail a test:

| Mutation | What the page would then say |
|---|---|
| `!php \|\| !wp` → `&&` | green **"Working — Running on WordPress 7.1.2 and PHP ."** |
| `String()` instead of a type-checked read | green **"Running on WordPress false and PHP 0."** |
| `measure.key in vitals` → `!== undefined` | unmeasured metrics silently dropped |
| `/^0/` instead of the anchored test | "0.5 MB" reported as **"nothing is cached yet"** |
| `! settings.enableCache` | an **absent** key read as "switched off" |

Also: a zero LCP is no longer a measurement (`good at 0 ms` is a missing reading wearing
a number) while a zero **CLS** still is — the live store genuinely holds seven. That
asymmetry is commented, because "0 is real" is only true of one metric.

### The lesson: I broke production again, and the same way

To verify a fix from a *master-based* branch I ran `rsync -a --delete` at the live site.
That removed the Overview from the deployed tree. The admin page **threw** — sidebar and
section title rendered, then the SPA died, because `tab-overview.js` was gone too.

This is the **second** time. Round 19: an `rsync --delete` from a master-based worktree
removed the Overview mid-review. The rule adopted then was not written down as a *mechanical*
rule, which is why I did it again four rounds later.

**The rule, now mechanical:** a deploy target is the *branch being reviewed*, never
`master`, whenever master lacks the files the live site is currently serving. The check
is one comparison, made before the copy:

```sh
# The live tree must match the head being deployed, or the deploy is a downgrade.
git -C <worktree> diff --stat HEAD -- src build   # must be empty
```

and after the copy, the reverse — the served bundle must contain the feature the live
page is using. A reviewer caught it before I did, and the right response was to restore
from the reviewed SHA rather than from whatever branch happened to be closest.

The second half of the lesson: **a review's evidence expires when the deployed tree
moves under it.** The reviewer's report is explicitly scoped to "the deployed bundle was
byte-for-byte `36dd9589` at 21:58". That is not a defect in the review; it is a property
of reviews against a live target, and it means the merge claim has to be re-checked after
any deploy, not inherited.
