# Design Variants — Performance Optimisation #709

Three static previews that re-imagine the same plugin screens. Open them directly in a browser — no build, no WordPress needed. Pick one to carry forward; the other two remain as reference.

**Issue:** https://github.com/nilesh32236/performance-optimisation/issues/709
**Branch:** `fix/design-chooser-variants` → PR vs `master`
**Preview paths:**
- `designs/variant-a/index.html` — Native WP
- `designs/variant-b/index.html` — Premium Command Deck
- `designs/variant-c/index.html` — Dense Utility

Each variant shows the **App shell + Dashboard + File Optimisation (Network tab)** with the same real copy and data, responsive at **640 / 768 / 992 / 1200** and using **`.wppo-` BEM** throughout.

---

## How to preview

```sh
# from plugin root
npx serve designs
# then open:
# http://localhost:3000/variant-a/index.html
# http://localhost:3000/variant-b/index.html
# http://localhost:3000/variant-c/index.html
```
Or open each `index.html` via `file://`. Each folder is self-contained (`index.html` + `style.css`, Inter via Google Fonts, no Font Awesome in A, minimal unicode glyphs elsewhere).

Switch between Dashboard and File Optimisation with the primary tabs / sidebar inside each preview. Resizing demonstrates the responsive breakpoints.

---

## Variant A — Native WP

**Tagline:** Longevity. Feels like core.

- **Layout:** No dark sidebar. Top `nav-tab`-style primary tabs (Dashboard, File Optimisation, Preload …). Wrap in `f0f0f1` admin grey, white `postbox` cards, `8px` grid.
- **Aesthetic:** WordPress admin primitives — subtle `postbox` borders, `4–8px` radius, WP blue `#2271b1` only for actions, no Font Awesome (unicode bullet/box glyphs), Inter 400/500/600.
- **Stats:** Four plain cards with left-accent colour, tabular numbers, muted meta, link arrow. No lift shadow or gradient hero — just `0 1px 2px` shadow.
- **File Optimisation:** Pill sub-tabs (`Assets / Scripts / E-Commerce / Network / Core`) inside a card. Switches use a WordPress-like toggle track (`36×20`). Code block is light `f6f7f7`.
- **Responsive:** Wrap `max 1280` with `24→20→16→12px` padding. Stats `4 → 2 →1` at `768 / 640`. Tabs scroll horizontally on small. Sidebar not needed — keeps `782px` WP collapse conflict irrelevant.
- **Strengths:** Survives any future wp-admin redesign, perfect RTL/keyboard, smallest CSS, easiest for contributors who know core.
- **Trade-offs:** Least “premium” in screenshots; won’t stand out in marketing galleries.
- **Choose A if:** You optimise for maintainability, accessibility and consistency with WordPress. Ideal when the plugin must look native for years.

---

## Variant B — Premium Command Deck

**Tagline:** Most wow in screenshots. Refined v5.

- **Layout:** Dark `0f172a` sidebar `260px` that collapses to `64px` rail (hover expands). Stores preference in `localStorage`. At `≤992` becomes overlay drawer with backdrop — fixes the `782–992` breakpoint gap from the audit. Mobile header `56px` appears only on small.
- **Aesthetic:** Premium v5 language — `16px` radius, `color-mix` soft accents, gradient top borders per stat card, glassy hero `linear-gradient(white → eef2ff → f0f9ff)`, `Inter 800` headings, container queries (`container-type:inline-size`) for stats/grid so cards reflow inside the content container not the viewport.
- **Dark mode:** Full `prefers-color-scheme: dark` token swap (`--wppo-bg-app #0b1220`, card `#111b2f`) — sidebar stays dark, hero and code blocks invert.
- **Stats:** Elevated cards with `3px` gradient stripe per variant, layered shadow that lifts on hover, footer CTA pill. Container queries: `4 → 2 → 1` inside the main container (not media query on body).
- **File Optimisation:** Same sub-tabs but tinted on soft `f8fafc` bar, warning banner for FOUC, dark code block (`0f172a`).
- **Implementation notes:** Most work (~3 days). Collapse needs `App.js` state + `localStorage wppo_sidebar_collapsed`; container queries are already supported in target browsers; dark mode is `html[data-wppo-theme]` if you want a toggle in Tools.
- **Strengths:** Highest perceived value, solves audit issues (container queries, `98dvh` replaced by `100dvh`, `782` breakpoint, backdrop blur gated), great for upsell / screenshots.
- **Trade-offs:** Dark sidebar can clash with some admin colour schemes if not themed; more CSS to maintain.
- **Choose B if:** You want the plugin to feel like a premium SaaS and to fix the v5 audit while keeping its spirit. Best for sales pages and the majority vote in audits.

---

## Variant C — Dense Utility

**Tagline:** Power users. Everything without scrolling.

- **Layout:** Three-column **toolbar + table + inspector**. Toolbar `48px` sticky with search and `⌘K` hint. Light `white` sidebar `220px` with navigation + collapsible “File — Sections” tree. Main table-dense area. Right inspector `320px` sticky on `≥1200`, hidden below (stacks into main flow). Layout grid `220 | 1fr | 320` → `220 | 1fr` at `1200` → single column at `768`.
- **Aesthetic:** Dense, utilitarian — `13px` base, `12.5px` tables, `JetBrains Mono` for numbers/code/kbd, `11px` uppercase labels, tight `8px` gaps, `1px` hairlines, tabular-nums. No gradients or lift — flat, scannable, Linear / VS Code inspired.
- **Palette:** Command style — `f8fafc` app, `white` cards, `e2e8f0` borders, `0f172a` primary for toolbar/buttons, accent `3b82f6`.
- **Interaction:** **CmdK palette** (`⌘K` / Ctrl+K) centred modal with filter and keyboard hints — demonstrates how 7 tabs and 50k images stay reachable without scrolling ~3000px waterfall. Table rows highlight and update the inspector; KPI bar is tabular with left accent and mono meta.
- **File Optimisation:** Dense form as a definition list (`180px` label | control) or stacked on mobile. Switches are compact `32×18`. Server rules share the same inspector detail.
- **Strengths:** Handles large sites (many images, LiteSpeed 4 modes) without waterfall bloat, fastest scanning for advanced users, best for developers managing multiple tabs.
- **Trade-offs:** Feels less friendly to first-time users; inspector hidden on laptop requires a click; denser UI needs more careful a11y testing.
- **Choose C if:** Your audience is power users / hosts managing many sites who want every control within one viewport and a keyboard-driven flow.

---

## Technical comparison

| Aspect | A — Native | B — Premium Deck | C — Dense Utility |
|---|---|---|---|
| **Sidebar** | None (top tabs) — stays clear of WP `782` collapse | Dark collapsible `260→64` + overlay `≤992` | Light `220` + inspector `320` |
| **Radius** | `8px` postbox | `16px` premium | `8px` flat |
| **Font** | Inter 400/500/600 | Inter 400/500/700/800 | Inter 400/500/600 + JetBrains Mono |
| **Grid** | `8px` media queries | Container queries + media fallbacks | Media queries, toolbar wrap |
| **Shadows** | `0 1px 2px` only | Layered `shadow` → `shadow-hover` + lift | Hairline only |
| **Icon system** | Unicode only (no FA) | Unicode + optional FA (preview uses unicode) | Unicode + mono kbd |
| **Dark mode** | Inherits wp-admin (auto) | `prefers-color-scheme` full | Desaturated, future toggle |
| **BEM** | `.wppo-tabs`, `.wppo-card`, `.wppo-stat` | `.wppo-sidebar`, `.wppo-stat`, `.wppo-hero` | `.wppo-toolbar`, `.wppo-nav`, `.wppo-kpi`, `.wppo-cmdk` |
| **Breakpoints** | `@media 640/768/992/1200` | `@container 560/900` + `@media 640/768/992` | `@media 640/768/992/1200` + toolbar wrap |
| **LiteSpeed slot** | Top notice `info` with badges | Dark-accent notice + hero meta | Toolbar meta + inspector detail |

All three keep the `App.js` tab contract (`dashboard / fileOptimization / preload / imageOptimization / databaseCleanup / objectCache / tools`) and `wppoSettings` shape — adoption is CSS + `App.js` sidebar/tabs reshuffle only. No REST, PHP or build changes.

---

## Selection guidance

- **Pick A** when you value native WordPress feel, minimal maintenance and accessibility over marketing flair. Safe for multi-year core changes.
- **Pick B** when you want the audit fixed but the premium v5 personality kept, with the strongest screenshots and a collapsible, spacious command deck.
- **Pick C** when your users run large catalogues, LiteSpeed fleets or need keyboard speed — density and inspector win.

Vote in **issue #709** or reply on the PR: comment `Keep variant-a`, `Keep variant-b` or `Keep variant-c`. The unchosen folders stay as reference until you confirm; then we port the winner into `src/css` + `App.js`.

## Validation

- `npm run lint:js` — passes (designs are static, no build touched).
- `composer lint` — unchanged PHP; `98dvh` and palette notes remain for the winner PR to address.
- Open each `index.html` at `640`, `768`, `992`, `1200` and verify no horizontal overflow and tabbable controls.
