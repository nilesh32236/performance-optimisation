## 2024-05-14 - Tooltip Hover and Focus Test Coverage
**Bug/Gap:** The `Tooltip` component was missing tests for user interaction events (`onMouseEnter`, `onMouseLeave`, `onFocus`, `onBlur`), leaving logic that controls tooltip visibility uncovered.
**Root Cause:** Interaction states were initially mocked out or overlooked in standard rendering tests.
**Test Added:** Added explicit `@testing-library/react` tests simulating `mouseEnter`, `mouseLeave`, `focus`, and `blur` events to ensure `wppo-tooltip-container--visible` is correctly added and removed.
## 2024-07-23 - FileOptimization Component Uncovered Branches
**Bug/Gap:** The `FileOptimization` component lacked test coverage for API failure sad paths, non-string input HTML escaping, and ignored keydown events during tab navigation.
**Root Cause:** The test suite only covered happy-path API responses and correct keyboard navigation, and the `escapeHtml` utility internal to the component wasn't tested with invalid inputs.
**Test Added:** Added explicit Jest tests simulating rejected API responses from `apiCall`, triggering an unsupported key event (`Enter`), and passing integers to the component's `nginx` server rules prop to ensure `escapeHtml` safely returns empty strings without throwing.
