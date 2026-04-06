## 2026-04-06 - Added ARIA attributes to Sidebar Toggle Button
**Learning:** Icon-only toggle buttons (like hamburger menus) require explicit `aria-label` and `aria-expanded` attributes to communicate their purpose and state to screen reader users in React applications. While visually apparent, screen readers may read the raw icon class or nothing at all if an `aria-label` isn't present.
**Action:** Always verify that icon-only interactive elements possess accessible labels and appropriate state attributes (`aria-expanded`, `aria-selected`, etc.) when implementing or reviewing UX elements.
