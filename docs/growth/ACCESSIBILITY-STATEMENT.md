# Accessibility statement — DRAFT

> **STATUS: DRAFT. NOT PUBLISHABLE YET.**
>
> This is a draft for the WordPress.org accessibility statement required by the plugin directory's
> Section 8. It is **not merged into `readme.txt` and must not be** until the accessibility auditor's
> gap list lands. A separate worker is producing that list now. Sections marked
> **[AUDITOR: FILL]** below are the places their findings have to go, and they are the reason this
> draft exists in this shape rather than as finished copy.
>
> **Do not remove the "not checked" list when the auditor's findings arrive.** That list is the
> point of the statement. A statement that claims a conformance level nobody has verified is worse
> than no statement, and it is the exact failure mode this plugin's claim register exists to stop.
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
| Target size | 44×44 CSS-pixel touch targets in the admin SPA | `AGENTS.md` (2.0.0 changelog entry) |
| Right-to-left and narrow viewports | Layout verified at 1280×800, 1024×768, and 390×844, plus RTL | `docs/growth/WORDPRESS-ORG.md`, Phase B capture notes |

The first two rows are the ones that matter most, and they exist because of a specific shipped
defect: a setting description was visible on screen and announced to nobody. That class of bug is
invisible to the unit suite, because Jest renders a stubbed `ToggleControl` with no generated id and
no `help` support — which is exactly why the check drives the shipped bundle in a real browser.

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

Section 8 expects the statement in the plugin readme. When the gap list arrives, this draft becomes
one `== Accessibility ==` section appended to `readme.txt`, placed after `== Support ==` and before
`== External Services ==`. The section to add, in the shape the directory expects:

```
== Accessibility ==

Performance Optimisation is not audited for WCAG conformance and makes no
conformance claim at any level. This section states what was checked and what
was not, so you can judge it for yourself.

Checked automatically: every control on the settings screens exposes an
accessible name and exposes its help text programmatically; decorative
affordances are removed from the accessibility tree; the inspector panel is a
live region; notices are announced with role="alert" and an appropriate
aria-live setting; touch targets are at least 44 by 44 CSS pixels; layouts
are verified at 1280x800, 1024x768 and 390x844 including right-to-left.

Not checked: no independent accessibility audit has been performed. No
screen reader has been used to operate the plugin, and no testing has been
done with people who use assistive technology. No automated WCAG conformance
scan is part of this project's tooling, and accessibility is not gated in
continuous integration.

If you hit a barrier, please report it at
https://wordpress.org/support/plugin/performance-optimisation/ with the
screen, the browser, and any assistive technology you were using. Reports of
this kind are treated as defects.
```

**Before that block is merged:**

1. Replace every `[AUDITOR: FILL]` marker with the auditor's finding, or with an explicit statement
   that nothing was found. Do not delete the markers — an unfilled marker means the statement is not
   finished.
2. If the auditor found defects, fix them or describe them. A published statement that names open
   accessibility defects is acceptable. A published statement that hides them is not.
3. Re-run `.a11y-check.js` and `.inspector-check.js` against the release commit and paste the real
   result into the row above. This draft does not claim they currently pass; **that has not been
   run for this version** and the numbers are unknown until somebody runs it.
4. Re-check the 44×44 and contrast claims against the design tokens actually shipping. Both are
   inherited from the 2.0.0 release and the design work since then has changed those tokens.

## Reporting a barrier

https://wordpress.org/support/plugin/performance-optimisation/

Include the screen, the browser, the assistive technology and version if any, and what you expected
to happen. Accessibility reports are treated as defects, not as feature requests, and they are not
answered with a feature request.
