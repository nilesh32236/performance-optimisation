## 2024-04-08 - Added Checkbox Descriptions and Inner Input Labels
**Learning:** Reusable checkbox components often have descriptions separate from the main label, and optionally render inner inputs (like textareas) when checked. Screen readers will not announce the description unless linked with `aria-describedby`, and the inner inputs won't inherit the parent checkbox's context and will lack an accessible name if not given their own label or `aria-label`.
**Action:** When building reusable form components, ensure descriptions are linked via `aria-describedby` to the main input, and any conditionally rendered inputs receive their own `aria-label` (or `<label>`) so they are announced properly.
## 2026-04-09 - [Loading States on Action Buttons]
**Learning:** Added visual loading spinners (`faSpinner` from `@fortawesome`) to buttons that handle async operations (like "Save Settings", "Clear Cache"). This provides immediate feedback to the user while waiting for server responses.
**Action:** Always include a visual loading state (spinner or explicit text change) for buttons triggering API calls in React components.

## 2024-05-14 - Missing Focus Indicators on Interactive Elements
**Learning:** Custom interactive elements (like custom tabs or buttons) that use `border: none` and `outline: none` (or missing focus styles entirely) introduce accessibility barriers for keyboard users because there's no visible indication of which element has focus.
**Action:** Always add explicit `:focus-visible` styling (such as `outline: 2px solid var(--wppo-primary); outline-offset: 2px;`) to interactive UI components in `src/css/style.scss` to ensure keyboard navigation is visible and clear.
