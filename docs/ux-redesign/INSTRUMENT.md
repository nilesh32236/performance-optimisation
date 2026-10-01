# Instrument — the shipped admin design

This documents the direction the admin is being migrated to. It sits alongside
[`DESIGN.md`](DESIGN.md), which records the earlier audit-driven pass and is
still the source of the audit findings in [`ui-audit.yaml`](ui-audit.yaml).
Where the two disagree, this file describes what is actually in the code.

## What changed, and why

`DESIGN.md` argued for a cool instrument neutral with tabular monospace for
every measurement, hairlines instead of shadows, and no composite score. All of
that survives. What this adds is the part that document did not specify: **a
threshold rail for every measurement, and a persistent inspector that explains
whatever control is focused.**

The reason is the shape of this plugin's settings surface. It has 246 fields
across 14 tabs, and many of them carry real consequences — a stylesheet that
flashes unstyled content, a deferred script that breaks a cart, a cache rule
that serves a stale page. The question a site owner actually has is never *what
is this control called*. It is:

> is this safe for my site, and what changes if I turn it on?

Today that question is answered by a small grey line under a label, which is
not enough room for the answer and not where anyone looks.

## The two signatures

### 1. The threshold rail

A performance metric is not pass/fail. It is a position on a scale with real
published boundaries, and the useful question is not "am I green" but "how much
room do I have". A status pill answers the first; the rail answers the second.

```
  Loading (LCP) — on mobile
  ▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁
  │◀── good ──▶│◀── needs work ──▶│◀── poor ──▶
          ▲ 3.4 s
```

`ThresholdRail` takes `value`, `thresholds: { good, poor }`, and an optional
`domain`. Thresholds are the published ones — LCP 2500/4000 ms, CLS 0.1/0.25,
INP 200/500 ms — and they are **passed in from the same table the verdict is
computed from**, never re-declared at the render site. Re-deriving them is how a
chart and a verdict end up disagreeing at the boundary, which this codebase has
already been bitten by once.

An unmeasured metric renders an **empty dashed track** with the label "Not
measured yet". It is never given a zero marker and never given a signal colour.
*Not measured* is idle, not failing.

### 2. The inspector

A persistent 344px panel that explains the focused control, always answering
the same three questions in the same order:

| Block | What goes in it |
|---|---|
| **What it does** | One plain sentence from the site owner's side, then at most two more. Never the internal mechanism. |
| **What it costs you** | The consequence, stated plainly. A safe setting says so in one line. A risky one names the *specific* failure and its guard. |
| **Where you stand now** | The current value in mono, its state, and when it last changed or was measured. |

Below those, **related settings** that route to them — because the hardest part
of a 246-field surface is finding the other half of a decision.

#### Source precedence

Three things compete for the panel: pointer hover, keyboard focus, and an
explicit click. Their precedence is the whole behaviour:

```
hover  (1)  <  focus  (2)  <  pinned  (3)
```

- A **weaker source never displaces a stronger one**. This is what stops a mouse
  drifting across a settings list from replacing what someone navigated to with
  the Tab key.
- **Only the source that owns the panel may release it.** The mouse leaving a
  row does not blank a keyboard-focused subject.
- A **pin** survives the pointer leaving and the row losing focus, and is only
  replaced by another explicit action.

That logic lives in `lib/InspectorContext.js` as a pure reducer, not in the
provider. It is the easiest part of this feature to get subtly wrong, and as a
pure function it is tested without a DOM
(`lib/__tests__/inspectorState.test.js`).

## Tokens

Defined in `src/css/abstracts/_variables.scss` under the *Instrument layer*.
The legacy tokens above it stay until nothing consumes them, so screens migrate
one at a time rather than all-or-nothing.

| Role | Value | Note |
|---|---|---|
| canvas | `#F4F6F9` | behind the cards |
| surface | `#FFFFFF` | |
| surface-quiet | `#F8FAFC` | grouped-field header strips |
| ink | `#0C1420` | |
| ink-muted | `#4A5A6E` | body copy |
| ink-faint | `#7C8CA0` | eyebrows, meta |
| hairline | `#DDE3EA` | **structure, not shadow** |
| focus | `#1B5FD9` | actions and focus **only** |
| signal-good | `#0B8A5C` | measured, healthy |
| signal-watch | `#B26A00` | measured, outside target |
| signal-poor | `#C02626` | measured, failing |
| signal-idle | `#6B7A8D` | **not a fault** — unconfigured or unmeasured |

`idle` is the point of the scale. An unconfigured feature and an unmeasured
metric are both idle, and both stay visually quiet. Rendering "you have not
turned this on" in red trains people to ignore red.

Shadow is spent exactly once — on the collapsed inspector sheet below `xl`,
where the panel genuinely floats over content.

### Geometry

| | |
|---|---|
| nav | 236px, **light** |
| work area | fluid, content capped at **880px, left-aligned** (never centred, so a long settings list scans as one column) |
| inspector | 344px |
| radius | 8px / 5px |
| row padding | 12px vertical, hairline between rows |

The nav is light because the plugin renders inside wp-admin's own chrome, and a
dark slab fights the host rather than sitting in it.

### Type

Three families, one job each, all self-hosted.

| Role | Family | Used for |
|---|---|---|
| Display | Space Grotesk 600 | page and card titles, the inspector subject line. **Sparingly.** |
| Body | IBM Plex Sans 400/600 | all interface text |
| Measurement | JetBrains Mono 500/600 | **every number, handle, unit and code token** |

The mono is load-bearing, not a flourish: here the numbers *are* the content,
and tabular figures let a column of measurements be compared without reading
each one. Numerals always use `font-variant-numeric: tabular-nums`.

**Self-hosting was not optional.** This plugin exists to make pages faster, so a
webfont has to be earned. `abstracts/_fonts.scss` documents the four decisions:
self-hosted (no third-party DNS or connection), latin subset only (Google also
serves cyrillic, cyrillic-ext, greek, latin-ext and vietnamese for these
families; together they were the majority of the bytes), three **variable**
files rather than one per weight, and `font-display: swap`. Total: **88 KB**.
The fallback stacks in `_variables.scss` keep the layout identical if the files
are ever removed.

## Layout

```
┌──────────┬─────────────────────────────┬──────────────┐
│ nav      │ work area                   │ inspector    │
│ 236px    │ fluid, capped 880px         │ 344px        │
│ sticky   │ left-aligned                │ sticky       │
└──────────┴─────────────────────────────┴──────────────┘
        ↓ below xl (1200px)          ↓ below lg (992px)
  inspector becomes a bottom sheet    nav becomes a drawer
```

## Copy rules

The copy is the substance of this design, not decoration, and it is tested
(`components/file-optimization/__tests__/subjects.test.js`).

- **Write from the site owner's side of the screen.** Name what happens to their
  site, never the internal mechanism. "Stops WordPress loading emoji scripts",
  not "removes the `wp-emoji` handle".
- **A safe setting says it is safe, in one line.** Padding it with reassurance
  trains people to stop reading the block entirely.
- **A risky setting names the specific failure and its guard.** "A rule that only
  appears after a click can be stripped — the safelist is the guard." Vague risk
  ("may cause issues") is worse than no risk at all.
- **Never congratulate.** No exclamation, no "Awesome!". State the fact, name the
  cost, offer the next action.

The tests enforce the mechanical half of this: ids derived from keys so they
cannot drift, no internal names leaking into prose, no vague-risk phrasing, and
a `warn` tone on the settings that genuinely break pages.

## Adding a setting to the inspector

```jsx
<SettingRow subject={ CSS_SUBJECTS.minifyCSS }>
  <SwitchField label="Minify CSS" description="…" checked={ v } onChange={ fn } />
</SettingRow>
```

`SettingRow` **wraps** the existing field components rather than replacing them —
they already render their own label and description, so it adds only the wiring,
the pin affordance and the selected state. One component carries the behaviour
across all fourteen tabs, which is what keeps it identical everywhere.

Copy goes in a `subjects.js` next to the screen, not inline in the JSX: it can
be read as prose in one place, a translator can reach it without navigating a
4,000-line component, and it can be tested without rendering anything.

The pin affordance is deliberately `aria-hidden` and `tabIndex={-1}`. Focus
already loads the subject, so it adds nothing for a keyboard user, and giving it
a name duplicates the field's own name for assistive tech and adds a tab stop to
every setting on the screen.

## Migration status

| Screen | Status |
|---|---|
| Shell, tokens, type, rail, inspector | done |
| Overview › Summary | rails wired; still uses the legacy badges |
| Speed › Assets & Scripts (CSS card) | 4 fields wired |
| Speed › Assets & Scripts (rest), Preload, Images, Database Cleanup, Object Cache, Tools | **not started** |

Legacy tokens, `StatusBadge`, and the old card styles remain until their last
consumer is migrated.
