
# 2024-05-24 - Dynamic WP Theme Colors using `color-mix`

**Learning:** Hardcoded SCSS hex colors break the dynamic WP admin theme adaptation. Also, WP variables like `var(--wp-admin-theme-color)` represent hex codes and cannot be injected directly into `rgba()` in CSS.
**Action:** Replace hardcoded colors with `var(--wp-admin-theme-color)`. For soft/medium opacity variants, use modern CSS `color-mix()`: `color-mix(in srgb, var(--wp-admin-theme-color) 8%, transparent)` to safely apply transparency to dynamic CSS variables.

## 2024-05-24 - Consistent Button Loading States

**Learning:** Replacing action triggers (like buttons) with simple text (e.g. `<p>Loading...</p>`) while fetching data causes jarring layout shifts and hurts accessibility because focus can be lost.
**Action:** Always reuse the `LoadingSubmitButton` component for any asynchronous action. This ensures the button remains in the DOM, maintains its physical space, and gracefully displays a spinner internally to communicate progress.

## 2024-05-27 - Tooltip Keyboard Accessibility

**Learning:** Tooltips that appear only on `:hover` and aren't focusable are completely inaccessible to keyboard users. In addition, icons without an `aria-label` or `aria-hidden` attribute create confusing or empty experiences for screen readers.
**Action:** When implementing tooltips, make the container focusable (`tabIndex="0"`), use `aria-label` for screen readers, and add `aria-hidden="true"` to both the decorative icon and the text content (since the label covers it). Update SCSS to include `&:focus` and `&:focus-visible` states that mirror `&:hover`. Use `var(--wppo-text-main)` instead of hardcoded hex values for better theme integration.

## 2024-06-01 - Avoid Layout Shifts in Granular Button Arrays

**Learning:** When listing an array of features with individual "Action" buttons (e.g., Clean Database items), replacing button text with loading ellipses ("...") inside a standard `<button>` element breaks the layout bounds, introduces jarring UI shifts, and fails to announce state changes to screen readers properly. Additionally, replacing the original native `.wppo-button` classes entirely can break visual alignment.
**Action:** When refactoring granular action buttons to show loading states, replace the generic `<button>` with `<LoadingSubmitButton>`. Critically, preserve the original visual classes (e.g., `className="wppo-button wppo-button--secondary"`) and pass `isLoading={loadingState}` so that the component internally manages the loading spinner and accessible `aria-live` region while maintaining exact physical button dimensions.
## 2026-06-26 - WP Theme Adaptation for Backgrounds

**Learning:** Hardcoded white backgrounds (`#fff`) on forms, dialogs, and controls break WordPress theme adaptation.
**Action:** Replaced hardcoded `#fff` backgrounds with `var(--wppo-bg-card)` to ensure that components gracefully adapt to WordPress color schemes and remain accessible.
