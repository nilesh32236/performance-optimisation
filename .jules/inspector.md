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
## 2026-08-22 - Strict Types and WPCS Fixes in Minify Classes (PHPStan Level 5)
**Bug/Gap:** The Minify CSS, JS and HTML classes triggered several strict PHPStan errors (level 5) including implicit void returns violating string types, invalid string callbacks in array_filter, and offset errors from regex matches. Additionally, single line closures broke WPCS and a temporary debug script caused CI failures.
**Root Cause:** Using `return;` when a function requires a string or null, using `"strlen"` callback instead of a native closure in array_filter which generates deprecations in PHP 8.1+, directly accessing regex match indexes without checking availability, and leaving temporary scripts in the repo.
**Fix:** Added explicit PHPStan level 5 checks via phpstan.neon to prevent similar static analysis issues going forward. Added explicit fallback logic for preg_replace_callback: return null !== $updated ? $updated : $original;. Formatted closures to be multi-line. Removed stray get_comments.php; verified with phpcs/phpcbf.
## 2026-08-22 - preg_match array bounds in PHPStan
**Bug/Gap:** Defensive null coalesce `$type_matches[3] ?? $type_matches[2] ?? ''` triggered an 'always exists and is not nullable' offset error in PHPStan level 5.
**Root Cause:** When preg_match satisfies a later capture group in an alternation (e.g. group 3), PHP populates the preceding unmatched groups in the matches array as empty strings, guaranteeing group 2 exists.
**Test Added:** Replaced defensive coalesce with `$type_matches[3] ?? $type_matches[2]` which accurately reflects runtime state and passes static analysis.
