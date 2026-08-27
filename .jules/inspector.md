## 2024-05-14 - Tooltip Hover and Focus Test Coverage
**Bug/Gap:** The `Tooltip` component was missing tests for user interaction events (`onMouseEnter`, `onMouseLeave`, `onFocus`, `onBlur`), leaving logic that controls tooltip visibility uncovered.
**Root Cause:** Interaction states were initially mocked out or overlooked in standard rendering tests.
**Test Added:** Added explicit `@testing-library/react` tests simulating `mouseEnter`, `mouseLeave`, `focus`, and `blur` events to ensure `wppo-tooltip-container--visible` is correctly added and removed.
## 2024-07-23 - FileOptimization Component Uncovered Branches
**Bug/Gap:** The `FileOptimization` component lacked test coverage for API failure sad paths, non-string input HTML escaping, and ignored keydown events during tab navigation.
**Root Cause:** The test suite only covered happy-path API responses and correct keyboard navigation, and the `escapeHtml` utility internal to the component wasn't tested with invalid inputs.
**Test Added:** Added explicit Jest tests simulating rejected API responses from `apiCall`, triggering an unsupported key event (`Enter`), and passing integers to the component's `nginx` server rules prop to ensure `escapeHtml` safely returns empty strings without throwing.

## 2024-07-24 - [Test Added for ConfirmDialog keypress]
**Bug/Gap:** Missing test coverage for Enter and Space keypresses on the overlay of ConfirmDialog.
**Root Cause:** The onClick behaviour was mocked with Space and Enter but without specific unit tests for those events.
**Test Added:** Added fireEvent.keyDown simulate tests for 'Enter' and 'Space' on the 'presentation' role.

## 2024-07-24 - [Add edge case tests for ConfirmDialog keypress gating]
**Bug/Gap:** Missing test coverage for the gating logic e.target === e.currentTarget on the overlay of ConfirmDialog.
**Root Cause:** The tests didn't verify the negative case where a keypress bubbles from a child (e.g., pressing Enter while a button inside the dialog is focused), which should NOT trigger onCancel.
**Test Added:** Added fireEvent.keyDown simulate tests for 'Enter' and 'Space' on the 'dialog' role and asserting onCancel is not called.
## 2024-06-03 - Add comprehensive test coverage for PerformanceAudit component
**Bug/Gap:** [PerformanceAudit.js lacked test coverage for important flows including error boundaries, suggestion handling, specific API fields like Advanced Timings and caching states]
**Root Cause:** [New component implementation had initial tests that did not simulate complex state permutations based on runPerformanceScan mock responses]
**Test Added:** [Added multiple new test blocks in PerformanceAudit.test.js mapping out positive outputs, parsing of specific edge cases, developer mode toggle visibility changes and gracefully handling fallback mechanisms for suggestion timeouts and scanning failures]

## 2026-07-29 - Wrap jest advanceTimersByTime in act
**Bug/Gap:** Tests simulating auto-dismissing notifications or long-running timers fail or warn if state updates occur outside of the React update cycle.
**Root Cause:** Using `jest.advanceTimersByTime()` synchronously triggers `setTimeout` callbacks that call state setters like `setNotification`, throwing `act()` warnings or ReferenceErrors if `act` isn't imported from `@testing-library/react`.
**Test Added:** Ensured `act` is explicitly imported and wraps all `jest.advanceTimersByTime()` calls when verifying timeout-driven state changes in components.

## 2026-07-30 - Silence console errors for expected errors during test suite runs

**Bug/Gap:** Tests triggering expected API failures were writing ugly `console.error` logs to the test runner output, confusing developers.
**Root Cause:** The `DatabaseCleanup` component logs caught errors when the API fails using `console.error()`, which Jest mirrors directly to the CLI unless mocked.
**Test Added:** Mocked `console.error` locally using `jest.spyOn(console, 'error').mockImplementation(() => {});` specifically for the exact test cases where errors are simulated, and correctly restored the mock via `consoleSpy.mockRestore();`.

## 2026-08-01 - Test Timeout Issue with Async Polling Timers

**Bug/Gap:** Tests validating recursive async polling behavior (like checking `getPagespeedResults` periodically using `setTimeout`) would either hang or fail to match expected DOM changes when using `jest.runAllTimers()`.
**Root Cause:** `jest.runAllTimers()` advances all timers but does not wait for async promises to resolve between timer ticks. Since the polling logic awaits `getPagespeedResults` before queuing the next `setTimeout`, advancing all timers synchronously misses the intermediate Promise resolutions and the subsequent timers are never scheduled.
**Test Added:** Fixed by consistently using controlled `jest.advanceTimersByTime(interval)` enclosed in `act()` instead of `jest.runAllTimers()`, and appending empty `await act( async () => {} )` steps to force Jest to flush microtasks (resolved promises) between time advancements.

## 2026-08-10 - Dashboard Test Act Warnings

**Bug/Gap:** The `Dashboard` component tests log `act` warnings due to state updates happening in promises triggered during mount.
**Root Cause:** The `fetchDbCounts` is triggered in a `useEffect` on mount, updating state asynchronously. Tests were verifying the initial render synchronously but not waiting for the async mount-time state updates to finish.
**Test Added:** Extracted a shared `flushDashboardMount()` helper (waits for the `database_cleanup_counts` call and flushes the resolved promise inside `act()`) and called it after every `Dashboard` render so async state updates settle before assertions run.
## 2026-08-12 - Silence Expected Error Output During Testing
**Bug/Gap:** Tests triggering expected failures (e.g., mockRejectedValue) produced console.error output, which polluted test logs and could mask genuine issues. Dashboard tests attempting to render unmocked child components (`WebVitalsTrends`) similarly triggered network/promise errors during tests.
**Root Cause:** The `console.error` in the catch block of `fetchWebVitalsTrends` was output during testing. In `Dashboard.test.js`, the unmocked child component `WebVitalsTrends` attempted to fetch data on mount, triggering an unhandled rejection when the API was unmocked in the parent context.
**Test Added:** Added `consoleSpy = jest.spyOn(console, 'error').mockImplementation(() => {})` in the specific error case in `WebVitalsTrends.test.js` (with a restore) and added the missing `WebVitalsTrends` mock in `Dashboard.test.js` to silence all noise.

## 2026-08-18 - Missing Component Dependencies in Test Mocks
**Bug/Gap:** Dashboard component tests failed with a TypeError because a child component (`WebVitalsTrends`) invoked a new API function (`fetchWebVitalsTrends`) that wasn't included in the parent test's API mock.
**Root Cause:** When modifying child components to depend on new shared utilities, the parent component tests using strict object mocks for those utilities often fail because the new dependency isn't defined.
**Test Added:** Added the missing `fetchWebVitalsTrends: jest.fn()` to the `apiRequest` mock in `Dashboard.test.js` and provided a default resolved value before tests. Also wrapped async `fireEvent` clicks in `FileOptimization.test.js` in `await act( async () => {} )` to fix 'not wrapped in act' warnings.

## 2024-05-14 - Missing Function Expectations for is_multisite
**Bug/Gap:** The `phpunit` test suite in `InlineCssTest.php` was failing with `MissingFunctionExpectations: "is_multisite" is not defined nor mocked in this test.`
**Root Cause:** The `setUp()` method did not include mock definitions for `is_multisite`, `get_transient`, and `set_transient`, which are invoked by `Util::transient_key` when dealing with WordPress multisite checks inside `Cache` or `Util` classes.
**Test Added:** Added mock definitions for `is_multisite`, `get_transient`, and `set_transient` to the `setUp()` method in `InlineCssTest.php` to satisfy BrainMonkey expectations.
## 2026-08-18 - Fixed PHPStan HTML Minify Errors
**Bug/Gap:** Static analysis errors in `class-html.php` for `strlen` used in `array_filter` and array offset extraction for `preg_match` results breaking type-safety constraints.
**Root Cause:** PHP 8.1+ triggers deprecation warnings for `strlen(null)`, and the previous regex logic inverted extraction precedence, incorrectly checking the unquoted group 3 first rather than the quoted group 2.
**Test Added:** Replaced `array_filter(..., 'strlen')` with strictly typed closures `static function (mixed $val): bool`. Corrected regex capture group extraction logic to explicitly prefer group 2 first and fallback to group 3: `$type = '' !== ($type_matches[2] ?? '') ? ($type_matches[2] ?? '') : ($type_matches[3] ?? '');`. Note: No unit tests were added in this PR as the scope was strictly constrained to static analysis type-safety fixes.
## 2024-08-27 - Fixed PHPStan Errors in class-google-fonts.php
**Bug/Gap:** Static analysis errors in `class-google-fonts.php` where `wp_normalize_path` and `content_url` were potentially assigning null to string properties, and a boolean check `! $result && file_exists( $tmp )` where the right side was always true due to previous checks.
**Root Cause:** Missing explicit string casts for function returns that PHPStan considered possibly null, and redundant file existence checks after download failures.
**Test Added:** Added string casting `(string)` for the path/url property assignments. Simplified the cleanup condition by removing the redundant `file_exists($tmp)` check.
## 2024-08-27 - [Fix WPCS errors in InlineCssTest.php]
**Bug/Gap:** WPCS failures due to incorrect array formatting for PHPUnit test stubbing. The multi-line function call to `\Brain\Monkey\Functions\stubs` was incorrectly formatted.
**Root Cause:** The `stubs` function was called with an array mapped argument that wasn't multi-lined correctly. It triggered the `PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket` and `PEAR.Functions.FunctionCallSignature.CloseBracketLine` sniffs.
**Test Added:** Not applicable. I fixed the formatting directly by enforcing strict multi-line array wrapping.
## 2024-08-27 - Test mock formatting WPCS violations
**Bug/Gap:** WPCS enforces strict formatting rules for multi-line array arguments in function calls, and the `Brain\Monkey\Functions\stubs` call was generating a `Opening parenthesis of a multi-line function call must be the last content on the line` and `Closing parenthesis of a multi-line function call must be on a line by itself` error.
**Root Cause:** The `array()` argument was kept on the same line as the opening parenthesis of `stubs()`.
**Test Added:** Fixed `InlineCssTest.php` formatting and ran `vendor/bin/phpcs` to verify. No functional change.
