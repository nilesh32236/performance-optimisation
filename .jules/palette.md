## 2024-04-08 - Added Checkbox Descriptions and Inner Input Labels

**Learning:** Reusable checkbox components often have descriptions separate from the main label, and optionally render inner inputs (like textareas) when checked. Screen readers will not announce the description unless linked with `aria-describedby`, and the inner inputs won't inherit the parent checkbox's context and will lack an accessible name if not given their own label or `aria-label`.
**Action:** When building reusable form components, ensure descriptions are linked via `aria-describedby` to the main input, and any conditionally rendered inputs receive their own `aria-label` (or `<label>`) so they are announced properly.

## 2026-04-09 - [Loading States on Action Buttons]

**Learning:** Added visual loading spinners (`faSpinner` from `@fortawesome`) to buttons that handle async operations (like "Save Settings", "Clear Cache"). This provides immediate feedback to the user while waiting for server responses.
**Action:** Always include a visual loading state (spinner or explicit text change) for buttons triggering API calls in React components.

## $(date +%Y-%m-%d) - Added keyboard focus indicators

**Learning:** Custom components like inputs with `appearance: none` and custom buttons require explicit `:focus-visible` styles to ensure they remain accessible for keyboard navigation.
**Action:** When updating styles in `src/css/style.scss`, ensure all interactive elements and especially those with `appearance: none` explicitly define an `outline` when focused. Also, check ARIA attributes on buttons whose icons change based on state (like a menu toggle).

## 2026-04-12 - Missing Focus Indicators & Accessible Imports

**Learning:** Found multiple focus-related a11y regressions missing `:focus-visible` states across the SCSS and file input fields lacking `aria-label`s for file selections, along with missing loading indicators. Additionally, `npm run test` tests can easily fail if there is a discrepancy between standard code updates and corresponding tests, so we need to run them and fix test files when appropriate.
**Action:** When updating elements to visually appear accessible, verify the visual focus indicators using `outline` alongside `outline-offset`. Remember to always assign screen-reader-safe labels to form inputs and provide clear visual loading states on their action triggers. Run `npm run test` to verify there are no missing code/test couplings.

## 2024-05-14 - Missing Focus Indicators on Interactive Elements

**Learning:** Custom interactive elements (like custom tabs or buttons) that use `border: none` and `outline: none` (or missing focus styles entirely) introduce accessibility barriers for keyboard users because there's no visible indication of which element has focus.
**Action:** Always add explicit `:focus-visible` styling (such as `outline: 2px solid var(--wppo-primary); outline-offset: 2px;`) to interactive UI components in `src/css/style.scss` to ensure keyboard navigation is visible and clear.

## 2026-04-14 - Added clear descriptions to complex toggles
**Learning:** Many complex performance settings (like "Minify HTML" or "Delay JS") lack explanations, leaving non-technical users unsure of their impact.
**Action:** Always add clear, non-technical `description` props to `CheckboxOption` or similar form elements when implementing feature toggles to ensure users understand the setting's purpose.
