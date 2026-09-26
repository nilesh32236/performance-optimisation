# Audit: JavaScript & React Best Practices

You are auditing the React SPA and frontend JS of a WordPress plugin. The app mounts on `#performance-optimisation`, uses pure `useState` (no external state library), and calls a REST API via `apiCall()`.

## What to Check

### React Patterns
- `useEffect` dependencies array always correct — no stale closure bugs
- State updates batched correctly — no redundant `setState` calls in sequence that should be one update
- Component unmount cleanup: event listeners, AbortControllers, and timers cancelled in `useEffect` cleanup
- No direct DOM mutation inside React components (use refs or state)
- Keys on all list-rendered elements — no index-as-key where list items can reorder

### API Calls & Error Handling
- All REST calls go through `apiCall()` from `src/lib/apiRequest.js` — no raw `fetch()`
- Loading state set to `true` before call, `false` in both success AND error paths
- Error messages displayed to user — not swallowed silently
- 403 responses handled specially (nonce refresh) — consistent with `src/main.js` pattern
- AbortController used for requests that may be cancelled on unmount

### Accessibility (ARIA)
- Interactive elements (`<button>`, `<input>`) have accessible labels
- Toggle switches (`SwitchField`) use `aria-checked` and `role="switch"`
- Loading states announced via `aria-live` or `aria-busy`
- `ConfirmDialog` traps focus and returns focus on close
- Color contrast meets WCAG AA for all text on background combinations

### i18n Readiness
- All user-facing strings use `wppoSettings.translations.KEY || 'English fallback'`
- No hardcoded English strings in render output
- Pluralization handled correctly (not just `count + ' items'`)

### Frontend Lazy Loading (`src/lazyload.js`)
- IntersectionObserver correctly disconnects after observing all initial elements
- MutationObserver cleaned up if `lazyload.js` script is removed dynamically
- Deferred script loading triggered on `touchstart`, `mouseover`, `keydown` — all three
- No memory leak from listeners added multiple times on reconnect

### Bundle Size
- No large library imported entirely when only one function needed (e.g., `import _ from 'lodash'` vs `import debounce from 'lodash/debounce'`)
- Dynamic imports (`import()`) used for code paths only needed on user interaction

## Output Format

Write findings to the output file in JSON Lines format:

```jsonl
{"type":"summary","text":"Audited {target_dir}. Found X issues."}
{"type":"issue","severity":"critical|important|minor","file":"relative/path","line":42,"message":"What the issue is","suggestion":"How to fix it","inline":false}
```

## Severity Guide

- **critical**: Stale closure bug, missing cleanup causing memory leak, raw fetch bypassing nonce, missing ARIA on interactive control
- **important**: Swallowed error (no user feedback), index-as-key on reorderable list, hardcoded English string
- **minor**: Missing useCallback optimization, non-ideal dependency array, minor a11y improvement
