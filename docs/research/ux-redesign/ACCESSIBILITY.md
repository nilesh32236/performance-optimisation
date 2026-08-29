# ACCESSIBILITY.md — WCAG 2.2 AA Review (2026-08-29)

**System:** `src/css/abstracts/_variables.scss:1-85` vars `--wppo-primary #2271b1` derived `--wp-admin-theme-color`, 4 semantic `--wppo-success #059669` etc, focus ring `--wppo-focus-ring 0 0 0 3px var(--wppo-primary-soft):62` `base/_base.scss:108-127` global `:focus-visible 2px outline 2px offset` for `a/button/input/select/textarea/[tabindex]`.

## Keyboard
- **Roving tablist:** `App.js:251-274` `tabRefs` roving `ArrowLeft/Right` `Home/End` good; **Focus trap:** mobile sidebar `App.js:215-260` + `ConfirmDialog.js:1-182` `aria-modal` + `TabFallback spinner` `App.js:56` — keep.
- **Gaps:** `Tooltip.js:30` `span tabIndex 0` should be `button` with `role=button` `aria-describedby` — span not keyboard activatable with Enter/Space `Agent K`.

## Screen Reader
- `SwitchField.js:41` visible `<span wppo-switch-field__label>` + `ToggleControl label hideLabelFromVision:54-55` duplicates — visible span not `<label for>`; screen reader hears hidden input label but visible not associated. Fix: use `ToggleControl` visible label only + `aria-describedby` for description `:46 .wppo-text-muted`.
- `NoticeBanner.js:1-56` `role=alert`/`aria-live assertive/polite` + `onDismiss dismiss` `useNotice.js` — inconsistent `onDismiss` presence `Agent C` — make always dismissible or not.
- `PerformanceAudit.js:506-810` tables missing `scope` on `<th>` `K` — add `scope=col/row`.
- `wppoSettings` global `class-main.php:1565` injected `wp_localize_script` — dynamic updates not announced; add `aria-live` region for Health rings.
- `StatusBadge.js:1-56` `MetricCard.js` badge color not only cue — already text "Good/Poor" `PerformanceAudit:121` good.

## Color Dependence
- Health rings semantic colors `--wppo-success #059669` etc `_variables.scss:34-46` + text badge — not color alone.

## Focus States
- `:focus-visible` 2px good; keep through redesign `src/css/base/_base.scss:108`.

## Form Labels
- Textareas "Exclude from Combining" `FileOptimization.js:411` etc missing explicit `<label>` — `SwitchField` has but textarea `CheckboxOption.js` + `Tooltip` need `htmlFor`.
- `DatabaseCleanup.js:376` `dbRevMaxAge` numeric inputs need `min/max` + `aria-describedby`.

## Error Messages
- `useNotice notify type:error|success|warning|info` `src/lib/useNotice.js` auto-dismiss timer cleared `dismiss` + unmount — `NoticeBanner` `role=alert` assertive for error, polite otherwise `Agent` — keep pattern `Dashboard.js` `notice && <NoticeBanner type message onDismiss>`.

## Toggle Semantics
- `SwitchField.js:50-57` `ToggleControl` inherits WP a11y native checkbox + `role=switch` gap noted — WP handles but need `aria-describedby`.

## Tooltips & Dynamic Content
- `Tooltip.js:1-51` `tabIndex 0 aria-describedby id role tooltip:44` hover+focus `32-35` `useId:19` — should be `button` not `span`; dynamic `wppoSettings` updates need `aria-live`.

## Must Preserve
- Roving, traps, `ToggleControl`, `NoticeBanner` alert, `:focus-visible`.

