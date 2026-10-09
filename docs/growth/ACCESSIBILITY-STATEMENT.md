# Accessibility statement — DRAFT

> **STATUS: DRAFT. NOT PUBLISHABLE. HARD BLOCK.**
>
> **This document must not be merged into `readme.txt`, must not be published to WordPress.org, and
> must not be paraphrased into a "we're working on it" line, until both gates below are satisfied.**
>
> **Gate (a) — the auditor's gap list.** Not yet delivered. Every place its findings have to land is
> marked **[AUDITOR: FILL]**.
>
> **Gate (b) — a measured failure disclosure.** The admin redesign, as it stands, **fails several of
> the checks this statement would otherwise imply it passes**: contrast failures, focus indicators
> that are weak or obscured, and touch targets below 24 CSS pixels. The current draft listed what was
> *checked* and nothing about what *failed*, which makes the "checked" column read as an achievement
> list. A statement that lists what was checked without saying what failed is worse than no statement,
> because it is a statement that launders. See **Known open failures** below.
>
> ### Correction, 2026-10-02 — a fabricated citation was removed
>
> An earlier draft opened by calling this "the WordPress.org accessibility statement required by the
> plugin directory's Section 8". **There is no such requirement, and "Section 8" is not about
> accessibility.** Item 8 of the
> [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
> reads, in full: *"Plugins may not send executable code via third-party systems."* No accessibility
> or accessibility-statement requirement appears on that page or on
> [The WordPress.org Plugin Directory](https://developer.wordpress.org/plugins/wordpress-org/).
>
> This statement is therefore a **voluntary disclosure**, not a compliance obligation. It is still
> worth publishing — a site owner deciding whether to install a plugin that manages the WordPress
> admin deserves to know what was and was not checked — but it is being published because it is
> useful, not because a rule compels it, and it must not be described as required.
>
> **Do not remove the "not checked" list, and do not resolve an [AUDITOR: FILL] marker by guessing**
> when the findings arrive. A document with visible placeholders is honest. A document with
> placeholders resolved by guesswork is a lie with a cleaner surface.
>
> Written: 2026-10-02. Plugin version at writing: 2.4.0.

---

## The claim, stated precisely

Performance Optimisation does **not** claim WCAG conformance at any level, on any version of WCAG.
This draft is a statement of what was checked and what was not — not a conformance claim, and it
must not be edited into one.

## What was checked

Everything below is an automated check that exists in this repository and asserts a specific
property. These are real checks with real coverage gaps, and the gaps are listed after them.

| Check | What it asserts | Where |
|---|---|---|
| Accessible names | Every interactive control on five dense admin screens exposes an accessible name — not merely an adjacent `<label>` | `.a11y-check.js` |
| Programmatic descriptions | Every control that renders a `help` description exposes it programmatically (`aria-describedby`), not only visually | `.a11y-check.js` |
| No duplicate labels | A setting name is not painted twice within one row, which would make the row ambiguous to announce | `.a11y-check.js` |
| Decorative affordances removed from the tree | The pin affordance is `aria-hidden` and out of the tab order | `.a11y-check.js` |
| Live region | The inspector panel is a live region, so a focus-driven change is announced rather than silent | `.a11y-check.js` |
| Keyboard focus behaviour | Focusing a control loads its subject; tabbing away clears it; the loaded row is visibly marked | `.inspector-check.js` |
| Notice announcements | `NoticeBanner` renders `role="alert"` with `aria-live="assertive"` for errors and `"polite"` otherwise | `AGENTS.md` Module 3; `src/components/common/NoticeBanner.js` |
| Target size | **A 44×44 CSS-pixel target is claimed in the 2.0.0 changelog and is NOT currently true.** Known sub-24px targets exist in the redesign. See **Known open failures**. Do not present this row as a passed check. | 2.0.0 changelog entry, contradicted below |
| Right-to-left and narrow viewports | Layout verified at 1280×800, 1024×768, and 390×844, plus RTL | `docs/growth/WORDPRESS-ORG.md`, Phase B capture notes |

The first two rows are the ones that matter most, and they exist because of a specific shipped
defect: a setting description was visible on screen and announced to nobody. That class of bug is
invisible to the unit suite, because Jest renders a stubbed `ToggleControl` with no generated id and
no `help` support — which is exactly why the check drives the shipped bundle in a real browser.

**A note on how to read this table.** "Checked" means *a check exists and was run against the shipped
bundle*. It does not mean the check passed, and it does not mean the property holds everywhere. The
one row where the distinction is already load-bearing is Target size: the check exists as a written
claim, and the claim is false. Read the next two sections before reading anything in this table as an
accomplishment.

## What was NOT checked

This is the honest list. None of it is a formality.

- **No independent accessibility audit.** No third party has reviewed this plugin. Every check
  listed above was written by the people who wrote the code.
- **No assistive-technology testing.** No screen reader was used to operate this plugin. No NVDA, no
  JAWS, no VoiceOver, no TalkBack, no Dragon. No one has listened to this plugin read a settings
  screen aloud, because no one has.
- **No testing by a person who uses assistive technology daily.** Every check was written by sighted
  mouse-and-keyboard developers reasoning about what a screen reader would say.
- **No automated WCAG conformance scan.** axe-core is not a dependency of this project.
- **[AUDITOR: FILL]** — no user testing with disabled users of any kind.
- **[AUDITOR: FILL]** — no contrast verification by an independent tool. The 2.0.0 changelog claims
  "WCAG AA contrast" for the redesigned dashboard; that claim has not been re-verified against the
  current design tokens, and the tokens are still being changed on the `design/*` branches.
- **[AUDITOR: FILL]** — no review of the generated build assets versus the source SCSS for anything
  contrast-related.
- **No accessibility statement for the documentation site.** `docs/site/**` is out of scope here.
- **No statement about the WordPress admin chrome itself.** Only this plugin's own surface is in
  scope; WordPress core's admin accessibility is a separate question this plugin does not answer.

## Known open failures

**This section is the reason the statement cannot ship yet.** The admin redesign currently fails
several of the checks the table above would otherwise imply it passes. Listing only the checked
items would turn a known list of defects into a list of accomplishments.

The four failure classes below were reported by review of the redesign branch. **The measurements are
not yet attached**, and I have not invented them — no measurement artifact for contrast, focus-ring
strength, or target size exists in this repository today (checked: the only accessibility tooling
present on `design/variant-c-redesign` is `.a11y-check.js`, which asserts accessible names, help-text
association, duplicate labels, tree hygiene and a live region — it measures none of the three things
below).

| # | Failure, as first recorded | Measured state as of 2026-10-02 | Fixed by |
|---|---|---|---|
| F-1 | **Contrast failures** in the redesign. The "WCAG AA contrast" claim inherited from the 2.0.0 notes was unsupported. | **RESOLVED — 0 failures.** Measured live at 1440 across five screens, every text node inside `.wppo-toolbar` and `.wppo-section`, threshold 4.5:1 normal / 3:1 large. 35 failures -> 0. Seven tokens corrected, each the same hue family one step darker: `--wppo-ink-faint`, `--wppo-text-light`, `--wppo-success`, `--wppo-error`, `--wppo-info`, plus a `loading="lazy"` help-text colour that is **WordPress core's** `#757575`, not ours, and had to be overridden at the top level. | `c97ee033`, `3314f1a7`, `22427d76` |
| F-2 | **Obscured focused elements.** A focused control covered or clipped in some states. | **RESOLVED — 0 failures.** 1061 focusable elements, real `Tab` presses, box re-read *after* focus and scroll settle, eleven sample points per element rather than one centre point. 90 (naive, largely artefact) -> 9 -> 5 false positives -> **0**. The 5 were `.screen-reader-text` checkboxes inside their own `<label>` - the correct pattern, not a defect. Root cause was `.wppo-inspector__toggle`, which had **no stylesheet at all** and sat under the wp admin bar. | `e91147d8` |
| F-3 | **Weak focus rings.** Indicator visible but its contrast or thickness inadequate. | **RESOLVED — 0 failures.** 155 tab stops scoped to the plugin's own container. 466 -> 46 -> 11 -> 6 -> **0**. The last defect was structural and would not show in a screenshot: the global rule rings every button with `--wppo-primary`, and a `.wppo-button--primary` background *is* `--wppo-primary` - **1.00:1, invisible**. Also removed `outline: none` from the search-result rows. Separately `--wppo-focus-ring` is a box-shadow shorthand, so `outline: 2px solid var(--wppo-focus-ring)` was invalid and computed to `outline-style: none`. | `b577d733`, `22427d76` |
| F-4 | **Touch targets below 24 CSS pixels**, against an inherited 44x44 claim. | **RESOLVED — 0 failures** against the criterion's actual threshold. 104 raw under-24px targets, **0 failing** once WCAG 2.5.8's spacing exception is applied (a target under 24px passes when a 24px-diameter circle does not intersect another target's circle). The 44x44 figure remains a known-false claim; the criterion is 24x24. | `9d16a8c5` |

Three criteria were measured that this document did not anticipate as failures, and all pass:

| Criterion | Result | Evidence |
|---|---|---|
| 2.3.3 reduced motion | **honoured** — 12 animating elements go from `0.2s` to `1e-05s` under `prefers-reduced-motion: reduce` | `ce0e9e3d` |
| 1.4.12 text spacing | **0 failures** — no clipping, collapse, overlap or horizontal scroll under the four overrides the criterion names | `38d5f15d` |
| forced-colors | **0 contrast failures**; **150/150** tab stops keep a visible ring under `forcedColors: active` | `4ce29958` |

Each measurement has a committed probe under `scripts/` whose docblock records the false positive it was built to avoid, because six of these probes reported a confident wrong number on their first run.

**None of these are fixed by this document.** They are recorded so the eventual statement is true
rather than flattering. Two consequences for whoever finishes this:

1. The published statement must carry this list, in full, with the measurements attached — including
   any that have been fixed by then, with the version that fixed them. A statement that quietly drops
   fixed items reads as a statement that never had them.
2. The 2.0.0 "WCAG AA contrast" and 44×44 claims must be treated as **known-false** in the meantime.
   They are in `changelog.md` and cannot be edited retroactively, so the correction has to live in
   this statement and in `docs/growth/claims.md` (whose "deliberately not claimed" table already
   refuses to carry either claim forward).

## Known limitations the auditor should weigh

- `.a11y-check.js` and `.inspector-check.js` drive **one live site**, at one viewport, with one
  browser. Chromium only. A single-browser accessibility tree is a narrow slice of the space.
- The five screens listed in `.a11y-check.js` are the dense ones — File Optimisation, Preload,
  Image Optimisation, Database Cleanup, Object Cache. A screen not in that list is not covered, and
  a control added after the list was written is not covered.
- Both scripts require authenticated access to a live WordPress install. Neither runs in CI. There is
  no pull-request gate on accessibility, so a regression can merge green.
- A pass from these checks means "the properties this script asserts still hold". It does not mean
  "this plugin is accessible", and it must not be reported as though it did.

## Where this goes when it is finished

**No WordPress.org rule requires this.** It was previously written as though "Section 8 expects the
statement in the plugin readme"; that requirement does not exist and the section number was wrong
(see the correction at the top). This is a voluntary disclosure, published because it is useful to
somebody deciding whether to install an admin plugin, not because a rule compels it.

When both gates are satisfied, this draft becomes one `== Accessibility ==` section appended to
`readme.txt`, placed after `== Support ==` and before `== External Services ==`. **The block below is
a starting shape, not final copy** — in particular the "Known issues" half is a placeholder for the
measured failures and must not be shipped without them.

```
== Accessibility ==

Performance Optimisation is not audited for WCAG conformance and makes no
conformance claim at any level. This section is a voluntary disclosure. It
states what was checked, what was not, and what is currently broken, so you
can judge it for yourself.

Checked automatically: every control on the settings screens exposes an
accessible name and exposes its help text programmatically; decorative
affordances are removed from the accessibility tree; the inspector panel is a
live region; notices are announced with role="alert" and an appropriate
aria-live setting; layouts are verified at 1280x800, 1024x768 and 390x844
including right-to-left.

Not checked: no independent accessibility audit has been performed. No
screen reader has been used to operate the plugin, and no testing has been
done with people who use assistive technology. No automated WCAG conformance
scan is part of this project's tooling, and accessibility is not gated in
continuous integration.

Known issues: [AUDITOR: FILL — the measured contrast failures, obscured
focused elements, weak focus rings and sub-24px touch targets, with the
version that fixes each one.]

If you hit a barrier, please report it at
https://wordpress.org/support/plugin/performance-optimisation/ with the
screen, the browser, and any assistive technology you were using. Reports of
this kind are treated as defects.
```

**Note what was removed from that block and why.** An earlier draft of this readme block said "touch
targets are at least 44 by 44 CSS pixels". That sentence is **known to be false** (F-4 below) and it
was removed rather than softened. Do not put it back. The same applies to any contrast wording: the
2.0.0 "WCAG AA contrast" claim is known-false (F-1) and must not be restated here.

**Before that block is merged:**

1. Replace every `[AUDITOR: FILL]` marker with the auditor's finding, or with an explicit statement
   that nothing was found. Do not delete the markers — an unfilled marker means the statement is not
   finished.
2. Fill **Known open failures** below with measurements, and make sure every one of F-1 to F-4 is
   either in the published "Known issues" text or fixed in the version being published. If fixed,
   say which version fixed it. Do not drop fixed items: a statement that lists only what is still
   broken reads as though the rest never existed.
3. If the auditor found further defects, fix them or describe them. A published statement that names
   open accessibility defects is acceptable. A published statement that hides them is not.
4. Re-run `.a11y-check.js` and `.inspector-check.js` against the release commit and paste the real
   result into the row above. This draft does not claim they currently pass; **that has not been
   run for this version** and the numbers are unknown until somebody runs it.
5. Re-check the 44×44 and contrast claims against the design tokens actually shipping. Both are
   inherited from the 2.0.0 release, both are currently known-false, and the design work since then
   has changed those tokens.

## Reporting a barrier

https://wordpress.org/support/plugin/performance-optimisation/

Include the screen, the browser, the assistive technology and version if any, and what you expected
to happen. Accessibility reports are treated as defects, not as feature requests, and they are not
answered with a feature request.
