
# 2024-05-24 - Dynamic WP Theme Colors using `color-mix`

**Learning:** Hardcoded SCSS hex colors break the dynamic WP admin theme adaptation. Also, WP variables like `var(--wp-admin-theme-color)` represent hex codes and cannot be injected directly into `rgba()` in CSS.
**Action:** Replace hardcoded colors with `var(--wp-admin-theme-color)`. For soft/medium opacity variants, use modern CSS `color-mix()`: `color-mix(in srgb, var(--wp-admin-theme-color) 8%, transparent)` to safely apply transparency to dynamic CSS variables.

## 2024-05-24 - Consistent Button Loading States

**Learning:** Replacing action triggers (like buttons) with simple text (e.g. `<p>Loading...</p>`) while fetching data causes jarring layout shifts and hurts accessibility because focus can be lost.
**Action:** Always reuse the `LoadingSubmitButton` component for any asynchronous action. This ensures the button remains in the DOM, maintains its physical space, and gracefully displays a spinner internally to communicate progress.

## 2024-05-24 - Accessible Form Descriptions

**Learning:** Form inputs and textareas that have descriptive helper text (e.g., `<p className="wppo-text-muted">`) must be explicitly linked to that text using the `aria-describedby` attribute and a matching `id` for proper screen reader support.
**Action:** Always add an `id` to the helper text paragraph and reference it using `aria-describedby="[id]"` on the associated input element.
