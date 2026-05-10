
## 2024-05-24 - Safely Generating Gradients with WP Theme Colors

**Learning:** Hardcoded secondary colors in gradients (e.g., `#818cf8`) clash with dynamic WordPress admin themes when the user changes their primary color scheme.
**Action:** Use `color-mix()` to dynamically generate variant shades from the primary WP CSS variable for use in gradients. For example, replacing a hardcoded light purple with `color-mix(in srgb, var(--wppo-primary) 60%, white)` ensures the gradient always harmonizes with the user's active theme.

## 2024-05-24 - Dynamic WP Theme Colors using `color-mix`

**Learning:** Hardcoded SCSS hex colors break the dynamic WP admin theme adaptation. Also, WP variables like `var(--wp-admin-theme-color)` represent hex codes and cannot be injected directly into `rgba()` in CSS.
**Action:** Replace hardcoded colors with `var(--wp-admin-theme-color)`. For soft/medium opacity variants, use modern CSS `color-mix()`: `color-mix(in srgb, var(--wp-admin-theme-color) 8%, transparent)` to safely apply transparency to dynamic CSS variables.

## 2024-05-24 - Consistent Button Loading States

**Learning:** Replacing action triggers (like buttons) with simple text (e.g. `<p>Loading...</p>`) while fetching data causes jarring layout shifts and hurts accessibility because focus can be lost.
**Action:** Always reuse the `LoadingSubmitButton` component for any asynchronous action. This ensures the button remains in the DOM, maintains its physical space, and gracefully displays a spinner internally to communicate progress.
