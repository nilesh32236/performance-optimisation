
# 2024-05-24 - Dynamic WP Theme Colors using `color-mix`

**Learning:** Hardcoded SCSS hex colors break the dynamic WP admin theme adaptation. Also, WP variables like `var(--wp-admin-theme-color)` represent hex codes and cannot be injected directly into `rgba()` in CSS.
**Action:** Replace hardcoded colors with `var(--wp-admin-theme-color)`. For soft/medium opacity variants, use modern CSS `color-mix()`: `color-mix(in srgb, var(--wp-admin-theme-color) 8%, transparent)` to safely apply transparency to dynamic CSS variables.

## 2024-05-24 - Consistent Button Loading States

**Learning:** Replacing action triggers (like buttons) with simple text (e.g. `<p>Loading...</p>`) while fetching data causes jarring layout shifts and hurts accessibility because focus can be lost.
**Action:** Always reuse the `LoadingSubmitButton` component for any asynchronous action. This ensures the button remains in the DOM, maintains its physical space, and gracefully displays a spinner internally to communicate progress.

## 2024-05-23 - Dynamic Gradient Themes
**Learning:** Hardcoded hex colors (e.g., #818cf8) inside linear gradients break the dynamic theme adaptation of WordPress admin pages. Using a static secondary color prevents the gradient from harmonising with user-selected WP admin themes.
**Action:** Always use `color-mix()` combined with the primary native CSS variable (`var(--wppo-primary)` or `var(--wp-admin-theme-color)`) to generate gradient stops dynamically based on the current theme. For example: `color-mix(in srgb, var(--wppo-primary) 60%, white)`.
