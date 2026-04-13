## 2024-04-08 - Added Checkbox Descriptions and Inner Input Labels
**Learning:** Reusable checkbox components often have descriptions separate from the main label, and optionally render inner inputs (like textareas) when checked. Screen readers will not announce the description unless linked with `aria-describedby`, and the inner inputs won't inherit the parent checkbox's context and will lack an accessible name if not given their own label or `aria-label`.
**Action:** When building reusable form components, ensure descriptions are linked via `aria-describedby` to the main input, and any conditionally rendered inputs receive their own `aria-label` (or `<label>`) so they are announced properly.
## 2026-04-09 - [Loading States on Action Buttons]
**Learning:** Added visual loading spinners (`faSpinner` from `@fortawesome`) to buttons that handle async operations (like "Save Settings", "Clear Cache"). This provides immediate feedback to the user while waiting for server responses.
**Action:** Always include a visual loading state (spinner or explicit text change) for buttons triggering API calls in React components.
## $(date +%Y-%m-%d) - Added keyboard focus indicators
**Learning:** Custom components like inputs with `appearance: none` and custom buttons require explicit `:focus-visible` styles to ensure they remain accessible for keyboard navigation.
**Action:** When updating styles in `src/css/style.scss`, ensure all interactive elements and especially those with `appearance: none` explicitly define an `outline` when focused. Also, check ARIA attributes on buttons whose icons change based on state (like a menu toggle).
