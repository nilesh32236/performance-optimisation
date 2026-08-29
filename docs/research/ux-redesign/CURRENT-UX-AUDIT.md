# CURRENT-UX-AUDIT.md — Existing UI Complete Inventory (2026-08-29)

**Live site:** nileshportfolio.duckdns.org WP 7.1 PHP 8.3.33 LiteSpeed hit `server: LiteSpeed` `x-litespeed-cache: hit` — plugin active 1.9.0

## Information Architecture
- **7 flat tabs** `App.js:96-135` via `useState activeTab` no URL routing `App.js:76`: Dashboard, File Optimisation, Preload, Image Optimisation, Database, Object Cache, Tools — equal weight violates frequency (Dashboard read-only = Object Cache rare).
- **FileOptimization monolith 1970 lines** (`wc -l src/components/*.js` 1970) hides **5 sub-tabs** `FileOptimization.js:224-250` Assets/Scripts/E-commerce/Network/Core — actually 5 products collapsed, deep-link impossible (state only, no `?tab=file&sub=network`).
- **Dashboard 1329 lines** `Dashboard.js:1329` composes 8+ cards `PerformanceAudit 841 + PageSpeed 479 + WebVitalsTrends 229 + WebVitalsRum 177 + Suggestions 270 + SystemInfo 353 + ImageOptimizationCard 208 + Autoload 133 + AiPanel 291 + Edge 261 + Llms 152` = ~2800 lines on one route, >4000px scroll, 3 Save buttons.
- **Duplicate concepts:** CDN appears `Dashboard.js:1047` (purge) + `FileOptimization.js:1682` (hostname) + edge panel `EdgeCachePanel.js:261`; cache clear `Dashboard.js:774` + admin bar `class-main.php:537` + `src/main.js`.

## Navigation & Hierarchy
- Sidebar `App.js:96-135` `useMemo sidebarItems` icons `faTachometerAlt/faFileCode/faBullseye/faImages/faDatabase/faServer/faTools` `App.js:12-22` same style, UK spelling `File Optimisation` vs key `fileOptimization`.
- Mobile focus trap `App.js:215-260` + roving `251-274` works but overlay `457-467` traps scroll; no breadcrumb for sub-tabs.
- No onboarding flow beyond `WelcomePanel.js:9-56` 3 steps (cache, minify, lazy) top of Dashboard `Dashboard.js:800` auto-dismiss `WelcomePanel.js:78` after any `update_settings`.

## Settings Grouping
- **FileOptimization** groups implementation not intent: Assets (CSS+HTML) `333-734` (9 switches + 3 textareas), Scripts `737-1142` (minify/defer/delay + 5 delay sub-fields), E-commerce `1145-1258`, Network `1260-1720` (LS + server rules + CDN), Core `1721+` (11 bloat toggles) — user thinking “Make pages faster” must hunt across 5 subtabs.
- **Preload** mixes warmup `C1`, third-party `C3/C4`, critical assets `C5`, speculation `C6` `PreloadSettings.js:623` — 4 mental models flat.
- **Image** 4 sections `ImageOptimization.js:954` Lazy/Video/Next-Gen/Responsive — responsive limits `697-769` disconnected from lazy card.

## Labels & Descriptions
- Missing outcome phrasing: “Enable browser cache headers” `class-htaccess-handler.php` vs “Help browsers load returning visitors faster”.
- Technical labels forced as primary: `Defer JavaScript` `FileOptimization.js:737`, `Delay JavaScript Execution` `:901` + idle/viewport/priority/timeout `:936-1123`, `Combine CSS` `:358` — no benefit/risk line.
- Inconsistent: “File Optimisation” vs “FileOptimization” vs “Assets” vs “Core” vague.

## Forms / Toggles / Cards
- **~40 toggles** `FileOptimization.js:40-87 defaultSettings` 32 + 8 conditional + 12 textareas + 5 selects — `grep -c SwitchField` 28 + 12 textareas.
- Cards uniform chrome `src/components/common/FeatureCard.js` + `FeatureHeader.js` — no visual hierarchy between safe (Minify) vs high-risk (Combine `399-409` FOUC, Delay `1124` break).
- Textareas “one handle or partial URL per line” `FileOptimization.js:411-575` requires handle discovery via separate Asset Manager metabox `class-metabox.php:453` not linked.

## Notices & States
- **Loading:** `TabFallback spinner` `App.js:56-60` + `LoadingSubmitButton` `common/LoadingSubmitButton.js` per-card — good but 3 spinners on Dashboard compete.
- **Empty:** Database counts fetch `DatabaseCleanup.js:131-135` shows “0 items” + `AutoloadedOptions.js` empty OK; Object Cache when disabled shows status `922` but no “Enable to see metrics”.
- **Success/Warning/Error:** `useNotice()` `src/lib/useNotice.js` + `NoticeBanner` `common/NoticeBanner.js` `role=alert aria-live assertive/polite` per component — inconsistent `onDismiss` presence; yellow warnings fatigue `FileOptimization.js:400-408/1124/1166/1563`.
- **Disabled/Unsupported:** LiteSpeed paused `FileOptimization.js:347-357` shows banner `paused` but toggle still visible disabled; Nginx `other` shows code block `1631-1649` but toggle `enableServerRules` still rendered.
- **Conflict:** Only LiteSpeed conflict `Dashboard.js:706-758` banner; WP Rocket/FlyingPress/Cloudflare APO not detected `SystemInfo.js:633`.
- **Partial:** No “applied 2/3 optimizations, delay skipped” state.

## Onboarding
- `WelcomePanel.js:9-56` 3 checkboxes + `apiCall dismiss_welcome:113` — explains `cache → minify → lazy` but misses detection, server rules, CDN, recommended bundle; auto-dismiss loses remaining steps.

## Progressive Disclosure
- None: Sentinel/Cluster `ObjectCache.js:508-643` same level as Standalone; `blockAssetsOnDemand` `FileOptimization.js:81-82` WP 6.9+ auto hidden but still toggle; MIME override chips `ImageOptimization.js:608-669` WP 7.1+ gated late `506-517`.

## Visual Hierarchy & Design System
- `src/css/abstracts/_variables.scss:1-85` vars `--wppo-primary #2271b1` derived `wp-admin-theme-color`, 4 semantic `--wppo-success #059669` etc, focus ring `--wppo-focus-ring` `62`, breakpoints `sm 640 md 768 lg 992 xl 1200`.
- BEM-like `.wppo-` prefix, SCSS 18 partials `src/css/**/*.scss`, but FileOptimization monolith breaks rhythm.

## Accessibility
- **Good:** `SwitchField.js:50-57` uses `ToggleControl` + hidden label, `NoticeBanner.js:role=alert`, `ConfirmDialog.js:1-182` focus trap + `aria-modal`, `App.js:215-260` mobile focus trap + `251-274` roving tablist, `base/_base.scss:108-127` `:focus-visible 2px`.
- **Gaps:** `SwitchField.js:41` visible span not `<label for>` + no `aria-describedby` for description `46`; `Tooltip.js:30` `span tabIndex 0` should be `button` `role=button` `K` report; tables `PerformanceAudit.js:506-810` missing `scope`; dynamic `wppoSettings` global injected `class-main.php:1565` not announced.

## Real Bugs (Agent N QA 2026-08-29)
- `Server_Rules::is_litespeed()` `class-litespeed-integration.php:201` false in CLI vs real `server: LiteSpeed` header — LS detection mismatch.
- 681 Action Scheduler `failed` (missing PageSpeed API key) — no UI error for failed queue `PageSpeedPanel.js:479` shows spinner forever.
- 3 tabs + 3 subkeys missing vs `Util::ALLOWED_SETTINGS_KEYS:43` (`od_integration/bfcache/perf_translations` absent in DB `wppo_settings` 11/14) — backfill gap `class-util.php:43-70`.
- `voku/html-min` PHP 8.5 deprecation null-node warnings — silent but logs.

## Summary Score
Must fix: IA flat → grouped, monolith → pillars, outcome wording, Recommended one-click, progressive disclosure, conflict banner, emergency kill-switch, focus/aria gaps.

