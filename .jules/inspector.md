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
