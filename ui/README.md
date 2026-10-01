# Performance Optimisation — admin UI design reference

A browsable design reference for the WordPress admin screen of **Performance Optimisation**, a
free caching and performance plugin (`wppo_` prefix, v2.4.0). It exists to answer one question:
**which of three visual directions should this admin screen be?**

The same eight screens and the same 246 settings are built three different ways, so the choice is
a comparison of shells rather than of content.

## This is a static mockup

Read this before you judge anything:

- **It is not the working plugin.** There is no PHP, no REST route and no build step here. Nothing
  in this folder is meant to be lifted into the plugin as-is.
- **The data is fictional.** The site in every screen is a made-up coffee roaster. Every score,
  byte count, cache hit rate, image total and Lighthouse result is invented for the mockup.
- **Nothing is saved and nothing is sent anywhere.** The chooser page makes no network requests at
  all. The app requests two webfonts (Inter, JetBrains Mono) and only when the active theme is not
  `native`.
- It is here to **choose a look**, not to ship one.

## How to open it

It must be served from the `ui/` directory, because `app/index.html` loads `../data/settings.js`.

```sh
# from the plugin's ui/ parent directory
python3 -m http.server 8000
# then open:
#   http://localhost:8000/ui/index.html
```

`app/index.html` also works from `file://` if you open it directly, since nothing is fetched over
the network except the two webfonts. Serving is still the better test, because it is the only way
to be sure the `../data/settings.js` path resolves.

## The three themes

| | Theme | Who it suits |
|---|---|---|
| **A** | **Native WP** — grey `#f0f0f1` admin page, horizontal `nav-tab` navigation, white postboxes, 4px corners, the system font stack, WP blue on actions only. | Longevity. For anyone who wants the plugin to still look like wp-admin in five years and the smallest stylesheet to keep. Best RTL and keyboard behaviour. |
| **B** | **Premium Command Deck** — dark `#0f172a` sidebar that collapses to a 64px rail and becomes an overlay drawer at ≤992px, 16px corners, gradient stat stripes, a glassy hero, Inter 800, container-query grids and a full `prefers-color-scheme` dark mode. | Premium feel. For a plugin that should read like a paid product, and for sales pages that need good screenshots. |
| **C** | **Dense Utility** — three columns (nav 220px · content · inspector 320px), 13px base, JetBrains Mono numerals, 1px hairlines, no gradients or lift shadows, a sticky 48px toolbar with the ⌘K command palette. | Power users. For large catalogues, LiteSpeed fleets, and anyone who would rather type a setting name than scroll for it. |

Each theme has a deep link that opens the app already switched:

```
ui/index.html                      the chooser (this page's index)
ui/app/index.html?theme=native      theme A
ui/app/index.html?theme=deck        theme B
ui/app/index.html?theme=utility     theme C
```

Inside the app you can also switch themes from the floating control in the bottom-right corner.
That control is a mockup affordance, not part of the plugin.

## File map

```
ui/
├── index.html                    the chooser: three directions, scope, how to review, pick one
├── README.md                     this file
├── app/
│   ├── index.html                the app shell; all markup is rendered into it by JS
│   ├── css/
│   │   ├── tokens.css            brand primitives + the structural tokens each theme turns
│   │   ├── base.css              reset, typography, the shared component vocabulary
│   │   ├── settings.css          form, table and notice styling
│   │   ├── layout.css            the shell grid, the preview bar, the palette and dialogs
│   │   ├── theme-native.css      theme A
│   │   ├── theme-deck.css        theme B
│   │   └── theme-utility.css     theme C
│   ├── js/
│   │   ├── store.js              state, routing, the ⌘K index over the settings schema
│   │   ├── mock.js               the stand-in for the plugin's transport
│   │   ├── render.js             all markup
│   │   └── app.js                bootstrap, delegated events, keyboard handling
│   └── data/
│       └── mock.js               window.WPPO_MOCK — the fictional sample data
└── data/
    └── settings.js               window.WPPO_SETTINGS_SCHEMA — the settings inventory
```

CSS is loaded in that order on purpose. `tokens.css` is layer 1 and holds only custom properties,
so the three theme files are pure overrides of the same variables — which is what makes the
directions comparable rather than merely similar.

## `data/settings.js` is the source

`data/settings.js` is the machine-readable settings surface: **14 tabs and 246 fields**, grouped
by area and card, each field carrying its key, label, type, default, allowed choices, min/max,
unit, help text and the rule that decides when it is shown (`showIf`).

It is the single source the mockup is generated from, not a description of it:

- the **forms** on every settings screen are built by walking this file;
- the **⌘K palette** is indexed from it — 251 entries, being 8 screens plus 243 of the 246 fields.
  The three it skips are the `enabled` switches on the three read-only tabs (OD Integration,
  Back/Forward Cache, Performance Translations), which report status and have nothing to configure.
- 65 of the 246 fields are conditional: they stay in the DOM and have their `hidden` attribute
  toggled when the setting that governs them changes.

If you change this file, the forms and the palette change with it. There is no second copy of the
inventory anywhere in the folder.

## Verifying a change

There is no browser in the environment this was built in, so verification is done with jsdom:

Each harness starts its own static server on an ephemeral port, so no separate
server process is needed and none has to be left running:

```sh
node .scratch/smoke.js          # boots all 8 screens in all 3 themes, reports JS errors and content size
node .scratch/behaviour.js      # conditional visibility, the CDN repeater, dirty→save, ⌘K
node .scratch/chooser-check.js  # app inventory facts + structural checks on index.html
```

Each exits non-zero on failure.

jsdom has no layout engine, so none of these can catch horizontal overflow, real font metrics or
paint. Those still need a human in a real browser.

### Conditional fields

A `showIf` field is always present in the DOM — the app toggles the `hidden` attribute on a
`[data-when]` wrapper. Assert on the `hidden` **count**, never on the node count: a check that
counts nodes cannot detect the change and will fail forever. `behaviour.js` does this correctly.