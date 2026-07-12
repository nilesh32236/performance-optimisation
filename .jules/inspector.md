## 2024-05-15 - [QA: Static Analysis and API Tests]
**Bug/Gap:** The frontend codebase had numerous `no-undef`, `import/no-extraneous-dependencies`, accessibility (JSX a11y labels missing), and `no-console` warnings masking potential actual issues. The `src/lib/apiRequest.js` was entirely untested leaving network API call handling and failures unverified.
**Root Cause:** The project imports global parameters like `wppoSettings` that are injected by WordPress via `wp_localize_script`, but ESLint was not configured to recognize these globals. Accessibility for form fields lacked standard `htmlFor` attributes to pair labels and form controls. The project had no baseline JS unit tests setup.
**Test Added:** Created `.eslintrc.json` to formally declare WordPress globals. Fixed `react` import dependencies. Added tests in `src/lib/__tests__/apiRequest.test.js` to ensure the API requests construct the correct payload, parse the response properly, and safely handle and re-throw sad path network failure errors.
## $(date +%Y-%m-%d) - [JS Test Fix] fetchRecentActivities assertions
**Bug/Gap:** The Javascript test asserting API call of `fetchRecentActivities` was failing because the method implementation had been updated to execute a `GET` request and append a query parameter `?page=1` instead of using a `POST` request. In addition, there was a mismatch of expected log string output in sad-path tests ("Error fetching recent activities: " versus "Error fetching recent activities:").
**Root Cause:** A test file (`src/lib/__tests__/apiRequest.test.js`) not updated alongside implementation (`src/lib/apiRequest.js`).
**Test Added:** Fixed the unit test assertion matching expectations to proper implementations. Prevented future breaks.
## 2026-04-16 - [JS Test Fix] Testing UI component in jsdom
**Bug/Gap:** Tests for components missing DOM implementation and babel config.
**Root Cause:** Jest uses node by default if testEnvironment isn't specified, and misses WP-specific transpilation.
**Test Added:** Tested the interaction behaviors, updated `package.json` with `testEnvironment: jsdom`, set `babel.config.json` to use `@wordpress/default`, and wrote `setupTests.js`.
## 2026-05-26 - [JS Test Fix] Unhandled Console Errors in UI Tests
**Bug/Gap:** Tests for components (DatabaseCleanup and SystemInfo) that simulated API or network failures were triggering unhandled console.error logs to stdout during test runs.
**Root Cause:** Network failure tests caught errors effectively in their try/catch blocks but logged them to the console. The tests did not intercept or spy on `console.error` to ensure the correct errors were actually being reported, allowing the logs to pollute test outputs.
**Test Added:** Appended a `jest.spyOn(console, 'error')` spy and `expect(consoleSpy).toHaveBeenCalledWith()` assertion to assert expected error logs, followed by `.mockRestore()` to keep test runs clean and functionally rigorous.
## 2024-05-18 - [JS Test Fix] Testing SwitchField toggle disabled prop
**Bug/Gap:** SwitchField.js component wrapper for WordPress' ToggleControl lacked a disabled property mapping.
**Root Cause:** The `disabled` prop was ignored from `SwitchField.js` prop destructuring. Mock components of ToggleControl in tests were missing support for the disabled property. FileOptimization test asserting conditionally enabled or disabled field depending on apache vs nginx configuration was failing.
**Test Added:** Added disabled property mapping from `SwitchField.js` component to `ToggleControl` from `@wordpress/components`, mapped same property for `setupTests.js` mock components, and created `FileOptimization.test.js`.
## $(date +%Y-%m-%d) - [JS Test Fix] Testing async keyboard focus navigation
**Bug/Gap:** Keyboard tab navigation tests for `FileOptimization.js` failing asserting element focus using `.toHaveFocus()`.
**Root Cause:** Component implementations for moving focus asynchronously used `setTimeout(..., 0)` internally, causing the assertion to evaluate before the focus state had propagated.
**Test Added:** Updated the `fireEvent.keyDown` test flows to wrap the focus assertion inside `await waitFor(() => { expect(...).toHaveFocus(); });` to wait for the DOM mutation.
## $(date +%Y-%m-%d) - [CI Fix] Node 20 Deprecation Warning
**Bug/Gap:** CI workflows for Qoder code reviews failing due to actions using deprecated Node.js 20 runner environments.
**Root Cause:** GitHub Actions runner deprecating Node 20 in favor of Node 24 causing the checksuite to fail or warn heavily during the action lifecycle.
**Test Added:** Added `FORCE_JAVASCRIPT_ACTIONS_TO_NODE24: true` environment variable to the `actions/checkout@v4` step in CI workflows to opt-into the node 24 runtime manually.
## 2024-06-06 - [JS Test Fix] Testing UI React components edge cases
**Bug/Gap:** Some React UI components had uncovered lines handling edge cases such as rendering fallback components or API responses with 'success: false' rather than network errors.
**Root Cause:** Component test coverage lacked thorough assertions for alternative code paths.
**Test Added:** Implemented extensive frontend unit testing coverage using Jest + RTL to test these sad paths for SystemInfo and DatabaseCleanup components. Added mock assertions, timeout delays mocking using jest.useFakeTimers and explicit DOM interaction testing for React ConfirmDialog component.
## 2026-07-12 - [JS Test Fix] Testing useEffect updates across component rerenders
**Bug/Gap:** Component logic updating props triggered during renders by `useEffect` hook without test coverage (e.g., when a server rule sets to a different environment conditionally disabling switches).
**Root Cause:** Component test coverage lacked thorough assertions for dynamic state/prop changes on conditional events during re-rendering, specifically ignoring non-user interactions that dispatch property overrides like disabled.
**Test Added:** Implemented `rerender` test with RTL across variable conditions and mapped UI assertions against unmodifiable properties via screen.queryByLabelText() / expect().toBeDisabled() / not.toBeChecked().
