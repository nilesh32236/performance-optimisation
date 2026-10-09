# Design system — Performance Optimisation admin

The subject is not "a settings page." It is **a stopwatch and a logbook.** A site
owner opens this plugin once to answer one question: *is my site fast, and what
should I do about it?* Every design decision below serves that question.

Everything in the current UI is wrong in the same way: it treats thirteen kinds
of information as thirteen equal cards. That is why it reads as templated — not
because the palette is ugly, but because nothing is a thesis.

## The signature

**Every measurement in this plugin is set in tabular monospace.**

Not headings, not body — the *numbers*. Loading time, cache size, item counts,
scores, byte totals. Tabular figures so digits align down a column, so a
difference between two rows is visible without reading either. An interface made
of measurements should look like an instrument, and instruments set their
readouts in mono. It is one decision, applied everywhere, and it does more to
make this look designed than any amount of colour.

The second half of the signature: **one large reading at the top**, with a delta
and its provenance, instead of a score out of 100. A stopwatch tells you how long
it took. "92/100" tells you nothing you can act on, and it is the thing the
campaign already removed.

## Palette

Deliberately **not** WordPress admin blue as the carrier. WP blue stays for links
and form controls, because a plugin that looks alien inside wp-admin is a bug.
The plugin's own surface is a cool instrument neutral with one deep-teal accent,
which is nowhere near the cream/serif/terracotta, acid-on-black, or
broadsheet-with-hairlines defaults.

| Role | Hex | Note |
|---|---|---|
| `readout` | `#0B1220` | near-black navy; the big number, the page's anchor |
| `readout-muted` | `#5A6473` | its unit, its qualifier, its provenance |
| `page` | `#F6F7F9` | behind the cards; cooler than `#F1F1F1` admin grey |
| `card` | `#FFFFFF` | |
| `rule` | `#E3E6EB` | hairline, 1px. Structure, not shadow |
| `accent` | `#0B6E75` | deep teal — the instrument colour, not WP blue |
| `good` | `#0F7B4F` | measured and healthy |
| `warn` | `#8A5A00` | measured and outside the target |
| `bad` | `#B3261E` | measured and failing |
| `idle` | `#6B7280` | not measured — and this must be visually *quiet* |

Shadow is spent once, on the one sticky commit bar. Everything else uses a
hairline. Ten cards each carrying a shadow is the single strongest "generated"
signal in the current build.

## Type

| Role | Stack | Treatment |
|---|---|---|
| Reading | `Inter, "SF Pro Display", system-ui` | 600 weight, `-0.02em` tracking, tight leading |
| Body | `system-ui, -apple-system, "Segoe UI", sans-serif` | 15px/1.55, `#0B1220` |
| Utility | same as body | 13px, `readout-muted` |
| **Measurement** | `ui-monospace, "SF Mono", "JetBrains Mono", monospace` | `font-variant-numeric: tabular-nums` |

WordPress admin forces `-apple-system/Segoe UI` on body copy, so the reading
carries the personality instead — and it is the one place personality is
affordable.

## Layout

### Overview — "the page is a stopwatch, the rest is evidence"

```
┌──────────────────────────────────────────────────────────────┐
│  Your site loads in                                          │
│                                                              │
│      388 ms                          ← 56px readout, mono    │
│      on desktop, from a PageSpeed lab scan  ← provenance      │
│      ↓ 2.4× faster than last week      ← the delta, the point │
├───────────────────────────────────────┬──────────────────────┤
│ WHAT TO DO                            │ THE NUMBERS          │
│                                       │                      │
│ 1  Tap responsiveness isn't measured  │  LCP      388 ms      │
│    by a lab scan          [Fix ▸]    │  CLS      0.002       │
│                                       │  INP        —         │
│ 2  Two fonts are loading from a      │  cache     9 MB       │
│    third party              [Fix ▸]   │  images    412        │
│                                       │                      │
│ (three items. never more.)            │  everything else     │
└───────────────────────────────────────┴──────────────────────┘
```

**Max three items in "what to do."** If there are more, the plugin is failing at
its job, not the user. Everything not in the top three is one click away.

### Settings — "one thing to do, and it is named"

Every settings screen has **exactly one commit bar**, sticky to the bottom,
saying what it saves: *Save monitoring settings*. Never a bare "Save Settings"
on a screen with four of them. Destructive and export actions sit inside their
card, secondary, never in the bar.

### Messages — always visible, never off-screen

Every notice fires into one app-level region at the bottom right **and** renders
inline in its card. The inline copy is for context; the region is the one the
user can actually see. Today a message can render two screens above the viewport
and the user gets nothing.

## The revision, stated honestly

My first pass was a measurement table with a big number on top and blue accents
— which is the template answer, and what the current build already is. What
changed it:

1. **Tabular monospace for every measurement** rather than proportional sans.
   This is the decision that makes the thing look like an instrument. Nothing
   else I considered had that effect.
2. **Teal, not blue**, as the carrier. WP blue is retained for links and controls
   so the plugin still belongs in wp-admin.
3. **Hairlines, not shadows**, everywhere except the one commit bar.
4. **Max three actions** in the primary column, rather than ten.

I am not adding an illustration, an animation, or a second accent. The plugin's
subject is measurement; decoration would be a costume.

## What this kills

- Ten-link "Find a setting" grid → folded into the three-item column.
- Raw JSON in the Page Cache card → *"Last cleared 2 hours ago, 4 times this month."*
- Developer activity log on the Overview → off the Overview; human sentences only.
- Six buttons on Tools with two of them primary → one named commit bar.
- "No reading in the stored PageSpeed lab scan history yet" → *"Tap
  responsiveness isn't measured by a lab scan."*
