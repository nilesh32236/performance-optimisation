
# 2024-05-24 - Dynamic WP Theme Colors using `color-mix`

**Learning:** Hardcoded SCSS hex colors break the dynamic WP admin theme adaptation. Also, WP variables like `var(--wp-admin-theme-color)` represent hex codes and cannot be injected directly into `rgba()` in CSS.
**Action:** Replace hardcoded colors with `var(--wp-admin-theme-color)`. For soft/medium opacity variants, use modern CSS `color-mix()`: `color-mix(in srgb, var(--wp-admin-theme-color) 8%, transparent)` to safely apply transparency to dynamic CSS variables.

## 2024-05-18 - Replacing Swapped `<button>` and `<p>` With Consistent `<LoadingSubmitButton>`

**Learning:** Using conditional rendering to completely swap out an interactive element like a `<button>` for a non-interactive element like a `<p>` tag during loading states causes layout shifts and drops screen-reader focus, resulting in a poor accessibility experience.
**Action:** Always reuse the `LoadingSubmitButton` component (which handles `aria-busy`, maintains the DOM node, and displays a spinner seamlessly) rather than doing manual DOM element swapping for loading states.
