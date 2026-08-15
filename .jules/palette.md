
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
## 2026-08-03 - Replaced Hardcoded Color with CSS Variable
**Learning:** The video placeholder play button hover state used a hardcoded YouTube red color (`#cc0000`), which failed to adapt to the user's active WordPress admin theme. Also, its outline fallback used an outdated WP blue (`#007cba`).
**Action:** Replaced `#cc0000` with `var(--wppo-primary-hover)` and updated the outline fallback to `#2271b1` in `_video-placeholder.scss` to ensure consistent theme adaptation across the UI.

## 2026-08-05 - Accessible Loading States for Async Actions in Welcome Panel
**Learning:** Replacing action triggers (like standard `<button>` elements) with simple text updates (e.g. `Enabling...`) without proper ARIA attributes fails to maintain an accessible, visually consistent loading state during asynchronous operations.
**Action:** When refactoring async action buttons, always use the `<LoadingSubmitButton>` component to provide an accessible `aria-live` region and a visual spinner, preserving the physical button dimensions and layout.

## 2026-08-06 - Progress Bar Accessibility

**Learning:** The Hit Ratio progress bar in the ObjectCache component lacked proper ARIA attributes, making it inaccessible to screen readers. Users navigating with assistive technology could not perceive the visual progress representation.
**Action:** Added `role="progressbar"`, `aria-labelledby`, `aria-valuemin="0"`, `aria-valuemax="100"`, and `aria-valuenow` to the progress bar container in `ObjectCache.js` to ensure screen readers announce its state properly. Also assigned a unique ID to the label element.

## 2026-08-07 - Non-existent CSS variable for success colors
**Learning:** A hardcoded non-existent variable `var(--wppo-color-success)` was used instead of the correct `var(--wppo-success)` from `_variables.scss`, which means the color wasn't resolving to the defined design-system success color.
**Action:** When using inline styles or SCSS for status colors in React components, always verify the exact CSS variable name (e.g., `var(--wppo-success)`) against `src/css/abstracts/_variables.scss` rather than guessing or hardcoding values, so the icon resolves to the intended design-system color instead of silently falling back to an unstyled value. You can check for occurrences using `grep -rn "var(--wppo-color-" src/`.
