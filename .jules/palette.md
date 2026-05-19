
# 2024-05-24 - Dynamic WP Theme Colors using `color-mix`

**Learning:** Hardcoded SCSS hex colors break the dynamic WP admin theme adaptation. Also, WP variables like `var(--wp-admin-theme-color)` represent hex codes and cannot be injected directly into `rgba()` in CSS.
**Action:** Replace hardcoded colors with `var(--wp-admin-theme-color)`. For soft/medium opacity variants, use modern CSS `color-mix()`: `color-mix(in srgb, var(--wp-admin-theme-color) 8%, transparent)` to safely apply transparency to dynamic CSS variables.

## 2024-05-24 - Consistent Button Loading States

**Learning:** Replacing action triggers (like buttons) with simple text (e.g. `<p>Loading...</p>`) while fetching data causes jarring layout shifts and hurts accessibility because focus can be lost.
**Action:** Always reuse the `LoadingSubmitButton` component for any asynchronous action. This ensures the button remains in the DOM, maintains its physical space, and gracefully displays a spinner internally to communicate progress.

## 2024-05-19 - Refactor hardcoded gradient shades to adapt to WP Theme
**Learning:** Found that hardcoded hex colors (e.g., `#818cf8`) inside linear-gradients clash with dynamic WP Theme colors and fail to adapt cleanly to user preferences.
**Action:** When creating gradients or shade variants from native WP CSS variables (like `var(--wppo-primary)`), always use `color-mix(in srgb, var(--wppo-primary) 60%, white)` instead of hardcoded hex colors.
