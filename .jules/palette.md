
# 2024-05-24 - Dynamic WP Theme Colors using `color-mix`

**Learning:** Hardcoded SCSS hex colors break the dynamic WP admin theme adaptation. Also, WP variables like `var(--wp-admin-theme-color)` represent hex codes and cannot be injected directly into `rgba()` in CSS.
**Action:** Replace hardcoded colors with `var(--wp-admin-theme-color)`. For soft/medium opacity variants, use modern CSS `color-mix()`: `color-mix(in srgb, var(--wp-admin-theme-color) 8%, transparent)` to safely apply transparency to dynamic CSS variables.

## 2024-05-24 - Consistent Button Loading States

**Learning:** Replacing action triggers (like buttons) with simple text (e.g. `<p>Loading...</p>`) while fetching data causes jarring layout shifts and hurts accessibility because focus can be lost.
**Action:** Always reuse the `LoadingSubmitButton` component for any asynchronous action. This ensures the button remains in the DOM, maintains its physical space, and gracefully displays a spinner internally to communicate progress.

## 2024-05-27 - Tooltip Keyboard Accessibility

**Learning:** Tooltips that appear only on `:hover` and aren't focusable are completely inaccessible to keyboard users. In addition, icons without an `aria-label` or `aria-hidden` attribute create confusing or empty experiences for screen readers.
**Action:** When implementing tooltips, make the container focusable (`tabIndex="0"`), use `aria-label` for screen readers, and add `aria-hidden="true"` to both the decorative icon and the text content (since the label covers it). Update SCSS to include `&:focus` and `&:focus-visible` states that mirror `&:hover`. Use `var(--wppo-text-main)` instead of hardcoded hex values for better theme integration.
## 2024-05-29 - Dashboard Tab Navigation in Playwright\n**Learning:** Direct navigation to dashboard sub-tabs using anchor links (e.g. `#database-cleanup`) often causes timeouts in Playwright tests because the React application handles the routing internally after the main page loads.\n**Action:** When testing React dashboard tabs in WordPress with Playwright, navigate to the main plugin page first, wait for the React application to render (`page.wait_for_selector(".wppo-dashboard-view")`), and then use Playwright locators to explicitly click the desired tab element.
