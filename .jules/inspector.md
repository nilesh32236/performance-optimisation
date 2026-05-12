## 2024-05-12 - Added React component unit tests for UI primitives
**Bug/Gap:** Several shared components (`CheckboxOption`, `LoadingSubmitButton`, `SwitchField`, `Tooltip`) located in `src/components/common/` lacked test coverage, which could lead to uncaught regressions if these high-frequency primitives were altered.
**Root Cause:** Component test coverage wasn't mandated or written as these utility components were implemented.
**Test Added:** Added standard `@testing-library/react` tests in `src/components/common/__tests__/` to verify state toggling, `aria` accessibility properties, error handling paths, and rendering correctly under JSDOM. Note: testing `@wordpress/components` requires mocking `window.matchMedia` in `setupTests.js` to avoid runtime failures during DOM interactions.
