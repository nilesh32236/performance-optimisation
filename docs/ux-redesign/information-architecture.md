# Information architecture

## The problem, measured

Seven undifferentiated top-level tabs, proven live by walking each one:
`location.href` never changed, and the sidebar items are `<button>` elements
with **no `href`**. So a refresh lost the section, Back and Forward did
nothing, and a bookmark could not be shared.

Density was the second problem. `file_optimisation` alone carries **96**
settings inside a single **6,320-line** component, and `Dashboard` had become a
junk drawer hosting **13** panels.

## The five areas

```
Overview     What is working, what is not configured, what to do next.
Speed        Assets & Scripts · Preload
Media        Images
Data & System  Database Cleanup · Object Cache
Manage       Tools & Settings
```

Nothing was removed. Each of the seven original screens is still present and
is now reachable as a sub-item of exactly one area, so the worst case is one
extra click for a capability that used to be one click from the sidebar.

## URLs

```
/wp-admin/admin.php?page=performance-optimisation                      → overview
/wp-admin/admin.php?page=performance-optimisation&section=speed
/wp-admin/admin.php?page=performance-optimisation&section=media
/wp-admin/admin.php?page=performance-optimisation&section=data-system
/wp-admin/admin.php?page=performance-optimisation&section=manage
```

`section` is the query key; `page` and any other admin argument (nonce
included) are preserved. `useSectionRoute` is the single implementation, and
it reads the URL for the initial render, `pushState` for in-app navigation, and
`popstate` for Back/Forward — so the URL and the visible area cannot drift.

### Legacy and deep links

- An unknown `section` falls back to Overview rather than rendering nothing.
- `?view=<id>` deep links are honoured **only** when the area that owns the view
  is the one named in `section`, so `?section=manage&view=preload` cannot open a
  Speed screen under a Manage header.
- `?tab=<legacy>` from the old seven-tab scheme still resolves via
  `LEGACY_TO_AREA`, so old bookmarks and existing "task links" keep working.

## Verified live (Chromium, real admin session)

| Check | Result |
|---|---|
| bare URL | resolves to `overview`, sidebar highlights Overview |
| click each area | `section` updates to speed / media / data-system / manage |
| browser Back ×3 | data-system → media → speed, sidebar follows each time |
| browser Forward | media |
| refresh | section preserved, header matches |
| direct `?section=` for all five | correct heading and sidebar highlight |
| `?section=totally-bogus` | falls back to Overview |
| sub-navigation | renders with `role=tab`, `aria-selected`, roving `tabindex` |
| ArrowRight in sub-nav | moves the selection to the next screen |
| console | no React errors |

Two defects were found by this live pass and would not have been caught by
lint, the build, or the 1,089 unit tests that were already green:

1. `useSectionRoute` has a **named** export; `App.js` default-imported it. That
   is a runtime `TypeError` and a completely blank admin — webpack and ESLint
   both accept the code. Fixed, and `src/__tests__/app-smoke.test.js` now
   asserts `App` actually mounts, so the class of bug cannot return silently.
2. `SectionShell` rendered `item.icon` raw, but the IA map holds FontAwesome
   icon *definitions*. React error #31. Icons are now rendered through
   `FontAwesomeIcon`.

A third live finding was **a defect in my own test, not the product** — an
earlier pass reported "Tools is broken" because the selector hit the hidden
mobile nav. Corrected in `README.md` rather than filed as an issue.
