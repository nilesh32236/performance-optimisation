
# 2024-05-24 - Dynamic WP Theme Colors using `color-mix`

**Learning:** Hardcoded SCSS hex colors break the dynamic WP admin theme adaptation. Also, WP variables like `var(--wp-admin-theme-color)` represent hex codes and cannot be injected directly into `rgba()` in CSS.
**Action:** Replace hardcoded colors with `var(--wp-admin-theme-color)`. For soft/medium opacity variants, use modern CSS `color-mix()`: `color-mix(in srgb, var(--wp-admin-theme-color) 8%, transparent)` to safely apply transparency to dynamic CSS variables.

## 2024-05-24 - Consistent Button Loading States

**Learning:** Replacing action triggers (like buttons) with simple text (e.g. `<p>Loading...</p>`) while fetching data causes jarring layout shifts and hurts accessibility because focus can be lost.
**Action:** Always reuse the `LoadingSubmitButton` component for any asynchronous action. This ensures the button remains in the DOM, maintains its physical space, and gracefully displays a spinner internally to communicate progress.
## 2025-05-21 - Replace Hardcoded Gradient Colors with Native WP Variables
**Learning:** Hardcoded secondary colors (e.g., `#818cf8` used alongside primary colors in gradients for `wppo-progress-bar`, `wppo-audit-overview-card`, and `wppo-stat-item` hover effects) break dynamic WordPress theme adaptation.
**Action:** Always replace hardcoded brand or accent colors in gradients with dynamic variants based on `var(--wppo-primary)` and `var(--wp-admin-theme-color)`. For a lighter shade of the primary color, use `color-mix(in srgb, var(--wppo-primary) 60%, white)` to safely adapt to the active WP Admin Theme.
