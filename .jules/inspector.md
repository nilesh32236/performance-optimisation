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
**Root Cause:** Jest uses node by default if testEnvironment isn't specified, and misses WP specific transpilation.
**Test Added:** Tested the interaction behaviors, updated `package.json` with `testEnvironment: jsdom`, set `babel.config.json` to use `@wordpress/default`, and wrote `setupTests.js`.
