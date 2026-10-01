---
name: Instrument — Performance Optimisation
colors:
  canvas: '#F4F6F9'
  canvas-sunken: '#EDF1F6'
  surface: '#FFFFFF'
  surface-raised: '#FFFFFF'
  surface-quiet: '#F8FAFC'
  ink: '#0C1420'
  ink-muted: '#4A5A6E'
  ink-faint: '#7C8CA0'
  hairline: '#DDE3EA'
  hairline-strong: '#C3CDD9'
  primary: '#1B5FD9'
  primary-hover: '#1549AC'
  primary-soft: '#E8F0FE'
  on-primary: '#FFFFFF'
  signal-good: '#0B8A5C'
  signal-good-soft: '#E6F6EF'
  signal-watch: '#B26A00'
  signal-watch-soft: '#FFF4E0'
  signal-poor: '#C02626'
  signal-poor-soft: '#FDECEC'
  signal-idle: '#6B7A8D'
  signal-idle-soft: '#EEF1F5'
  rail-good: '#0B8A5C'
  rail-watch: '#E0A32E'
  rail-poor: '#C02626'
  rail-track: '#E4E9EF'
  rail-marker: '#0C1420'
typography:
  display-lg:
    fontFamily: Space Grotesk
    fontSize: 34px
    fontWeight: '600'
    lineHeight: 40px
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Space Grotesk
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 30px
    letterSpacing: -0.015em
  headline-md:
    fontFamily: Space Grotesk
    fontSize: 18px
    fontWeight: '600'
    lineHeight: 24px
    letterSpacing: -0.01em
  body-lg:
    fontFamily: IBM Plex Sans
    fontSize: 15px
    fontWeight: '400'
    lineHeight: 23px
  body-md:
    fontFamily: IBM Plex Sans
    fontSize: 13.5px
    fontWeight: '400'
    lineHeight: 21px
  body-sm:
    fontFamily: IBM Plex Sans
    fontSize: 12.5px
    fontWeight: '400'
    lineHeight: 19px
  label-md:
    fontFamily: IBM Plex Sans
    fontSize: 13px
    fontWeight: '600'
    lineHeight: 18px
  label-sm:
    fontFamily: IBM Plex Sans
    fontSize: 11px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.06em
  data-lg:
    fontFamily: JetBrains Mono
    fontSize: 28px
    fontWeight: '600'
    lineHeight: 32px
    letterSpacing: -0.02em
  data-md:
    fontFamily: JetBrains Mono
    fontSize: 13px
    fontWeight: '500'
    lineHeight: 20px
  data-sm:
    fontFamily: JetBrains Mono
    fontSize: 11.5px
    fontWeight: '500'
    lineHeight: 16px
rounded:
  xs: 3px
  sm: 5px
  DEFAULT: 8px
  md: 10px
  lg: 14px
  full: 9999px
spacing:
  base: 4px
  gutter: 8px
  row-pad-y: 12px
  card-pad: 20px
  section-gap: 28px
  nav-width: 236px
  inspector-width: 344px
  work-max: 880px
---

## Brand & Style

This is a control room for one website's performance. The person using it is not a performance engineer, but every decision they make here has a measurable consequence — a stylesheet that flashes unstyled content, a deferred script that breaks a cart, a cache rule that serves a stale page. The interface's job is to make those consequences legible *before* they happen, and to show the current state of the site without decoration.

The style is **instrumentation**: precise, cool, quiet, and dense with meaning rather than with pixels. It takes its cues from measurement equipment and datasheets — tabular numerals, hairline rules, threshold scales, calibrated labels — rather than from dashboard templates. Restraint is the aesthetic. Nothing glows, nothing floats, nothing is rounded for friendliness.

Two ideas carry the identity, and everything else stays out of their way:

1. **The threshold rail.** Core Web Vitals and performance metrics are not pass/fail, they are positions on a scale with real published boundaries. Any metric shown anywhere in the product is drawn as a short horizontal rail with the good / needs-work / poor zones, and the current value plotted as a solid marker. A bare green "Working" pill tells you a verdict; a rail tells you *where you stand and how much room there is*. This replaces status pills as the primary status expression on metrics.
2. **The inspector.** A persistent right-hand panel that explains whatever control or metric is currently focused, always answering the same three questions in the same order: **What it does** · **What it costs you** · **Where you stand now**. This is the answer to a settings surface of 246 fields across 14 tabs, where the real user question is never "what is this called" but "is this safe for my site, and what changes if I turn it on".

The tone is a competent colleague, not a marketing page. No exclamation, no congratulation, no "Awesome!". State the fact, name the risk, offer the next action.

## Colors

A cool, low-chroma instrument palette. `canvas` (#F4F6F9) sits behind white `surface` cards, separated by a single `hairline` (#DDE3EA) — depth comes from hairlines and spacing, not from shadow. Shadows are reserved for genuinely floating layers (the inspector's popovers, dialogs) and never used to decorate a card.

`primary` (#1B5FD9) is a confident, saturated blue used **only for actions and focus** — never for decoration, never for headings, never as a background wash. It is deliberately deeper than the WordPress admin blue so the plugin reads as its own tool while still sitting comfortably in wp-admin.

Status is a four-value scale with a soft container for each: `signal-good` (#0B8A5C), `signal-watch` (#B26A00), `signal-poor` (#C02626), `signal-idle` (#6B7A8D). **Idle is a first-class state**, not an error: an unconfigured feature and an unmeasurable metric both read as idle, because "you have not turned this on" and "this has not been measured" are not problems. Never render an unmeasured metric in red.

The `rail-*` values are the threshold rail's own scale. The rail is the one place where the palette is allowed to be slightly brighter (`rail-watch` #E0A32E), because a scale has to be read at a glance and at 4px tall.

## Typography

Three families, each with one job, and no more.

**Space Grotesk** carries headings and nothing else — page titles, card titles, the inspector's subject line. It is a technical grotesque with slightly idiosyncratic letterforms, used at 600 weight with negative tracking so headings read as *labels on equipment* rather than as editorial. It appears sparingly; a page with many headings has too many headings.

**IBM Plex Sans** carries all interface text — labels, descriptions, help copy, buttons, table content. It was drawn for technical and engineering contexts, holds up at 13.5px in dense rows, and has more character than the Inter-and-Roboto default without asking for attention.

**JetBrains Mono** carries every number, every code token, every handle, every unit. This is a load-bearing choice, not a flourish: in this product the numbers *are* the content, and a monospaced face with tabular figures lets a column of measurements line up and be compared at a glance. Sizes, times, percentages, byte counts, ratios, IDs and CSS handles are always mono. Body prose never is.

Numerals always use `font-variant-numeric: tabular-nums`. Eyebrow labels (`label-sm`) are uppercase at 11px with 0.06em tracking — used for section headers inside cards, never for button text.

**Implementation constraint.** This product's entire purpose is making pages faster, so shipping webfonts is a real cost that must be justified and bounded. If these families ship, they must be **self-hosted**, subset to latin, declared with `font-display: swap`, and only the weights actually used are loaded (Space Grotesk 600; IBM Plex Sans 400 + 600; JetBrains Mono 500 + 600). The fallback stack must be a competent system stack so a font failure never changes layout: `ui-sans-serif, -apple-system, "Segoe UI", Roboto, sans-serif` and `ui-monospace, "SF Mono", Menlo, Consolas, monospace`. Do not load a webfont to render a page about page speed and then regress LCP with it.

## Layout & Spacing

Three zones, left to right, on a single row that fills the viewport height:

```
┌──────────────┬────────────────────────────────────┬─────────────────────┐
│  NAV         │  WORK AREA                         │  INSPECTOR          │
│  236px       │  fluid, content capped at 880px    │  344px              │
│  fixed       │  scrolls independently             │  fixed, scrolls     │
│              │                                    │                     │
│  bolt        │  ┌─ page head ─────────────────┐   │  ┌─ subject ─────┐  │
│  ─────────   │  │ eyebrow                     │   │  │ what it does  │  │
│  Overview    │  │ Title                       │   │  ├───────────────┤  │
│  Speed    ●  │  │ purpose line                │   │  │ what it costs │  │
│  Media       │  │ [sub-tabs]        [actions] │   │  ├───────────────┤  │
│  Data        │  └─────────────────────────────┘   │  │ where you are │  │
│  Manage      │  ┌─ card ──────────────────────┐   │  └───────────────┘  │
│  ─────────   │  │ EYEBROW                     │   │                     │
│  v2.4.0      │  │ row  ▸ label / value        │   │  related settings   │
│              │  │ row  ▸ label / value        │   │  → jump to them     │
└──────────────┴────────────────────────────────────┴─────────────────────┘
```

The nav is **light**, not dark — it sits inside wp-admin's own chrome and a dark slab fights it. The inspector is always present at ≥1200px; below that it collapses to a bottom sheet opened by the same "explain" affordance, and below 768px the nav becomes a drawer and the work area is the only column.

Spacing is on a 4px base. Rows inside a settings card are `12px` vertical padding with a `1px` hairline between them — the card is a table, not a stack of cards. Card padding is `20px`. Sections are `28px` apart. Content inside the work area is capped at `880px` and left-aligned, never centred, because left alignment lets a long settings list scan as a single column.

## Signature components

**Threshold rail.** A `4px`-tall track, `min-width: 96px`, with three tinted zones (good / needs-work / poor) and a `2px`-wide full-height ink marker at the current value's position. Sits directly under or beside the metric's mono value. Thresholds come from the real published boundaries (LCP 2500 / 4000 ms, CLS 0.1 / 0.25, INP 200 / 500 ms, TTFB 200 / 500 ms) so the scale means something. An unmeasured metric renders an empty track with a dashed border and the label "Not measured yet" — never a zero marker, never a colour.

**Inspector.** Fixed `344px` panel, white, hairline-separated from the work area, with a sticky subject header. Content is always the same three blocks in the same order:

- **What it does** — one plain sentence, then at most two sentences of detail. Written from the site owner's side: "Stops WordPress loading emoji detection scripts on every page." Not "Disables the wp-emoji script handle."
- **What it costs you** — the consequence, stated plainly. Safe settings say so in one short line ("No known downside. Fully reversible."). Risky settings name the specific failure and the guard: "Can flash unstyled content on first visit. The safelist above is how you exclude problem stylesheets." Destructive settings get the poor-signal container.
- **Where you stand now** — the current value in mono, its state, and the last time it changed or was measured. If it has never been measured, say that.

Below those, a short list of **related settings** that routes to them — because the hardest part of a 246-field surface is finding the other half of a decision.

A control is focused for the inspector by hover, by keyboard focus, or by clicking its "explain" affordance. Keyboard focus always wins, so the inspector is usable without a pointer.

**State row.** A settings row is: label (600 weight) · one-line description in `ink-muted` · the control, right-aligned · and an "explain" chevron that loads it into the inspector. Rows that are conditionally hidden are not removed from the DOM and not silently dropped — they collapse with a short inline note ("Hidden — needs Remove Unused CSS on") so the user can see that the option exists and what gates it. Silently vanishing options are the single most confusing thing about this product today.

## Components

Buttons are `8px` radius, `32px` tall (default) or `26px` (small), 600 weight, `13px`. Primary is solid `primary`; secondary is white with a `hairline` border; quiet actions are text-only in `ink-muted`. Destructive actions are never styled as primary — they are secondary buttons with `signal-poor` text, and always confirmed in a dialog that names the specific thing being destroyed.

Inputs are `8px` radius, `32px` tall, `hairline` border, and take a `3px` `primary-soft` focus ring. Mono inputs (handles, URLs, CSS selectors) render in JetBrains Mono. Toggles are `36×20` with a `primary` on-state and a `hairline` off-state, and are always paired with a visible text label — never a bare switch.

Banners use the soft status containers with a `3px` left rule in the matching signal colour, and name the specific risk. The product's real warnings are: FOUC when combining CSS, `.htaccess` modification for server rules, cart breakage for WooCommerce asset removal, delayed-script breakage, and a disabled safe preset in aggressive mode.

Cards are `8px` radius, `1px` hairline, white, with a `20px` padding and an uppercase `label-sm` eyebrow. No drop shadow at rest. Grouped settings get a `surface-quiet` header strip rather than a nested card — nesting cards inside cards is what makes an admin screen feel bottomless.

Empty states name the next action. Errors state what failed and what to do, in the interface's voice, without apology.
