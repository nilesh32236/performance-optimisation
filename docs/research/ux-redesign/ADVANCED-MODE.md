# ADVANCED-MODE.md — Progressive Disclosure 4 Levels

**Principle:** "Simple by default. Powerful when needed." — hide 40 toggles `FileOptimization.js:40-87` behind tiered disclosure, not separate products.

## Levels
| Level | Label | Who | What visible | Example |
|-------|-------|-----|--------------|---------|
| 1 | Recommended | Normal Owner Persona 1 | Health header + 3 actions + Welcome wizard | cache ON, minify ON, lazy native ON |
| 2 | More control | Freelancer Persona 2 | Speed→CSS/JS/HTML toggles + Exclude textareas with picker | Defer `FileOptimization:737` + ExcludeDefer textarea `411` |
| 3 | Advanced | Developer Persona 3 | Collapsed accordion "Show advanced (9)" | Combine `358`, Delay `901`, UsedCSS `496`, Woo regex `1187`, Server raw `1631` |
| 4 | Developer/Diagnostics | Specialist Persona 4 | Diagnostics drawer + Tools + hooks `docs/hooks.md` + WP-CLI `class-wppo-cli-command.php` | Autoload `AutoloadedOptions:133`, SystemInfo `353`, `wppo_*` filters |

## Terminology per Level
- L1 hides technical: "Make files smaller" not "Minify" `TERMINOLOGY.md`
- L3 shows technical: "Minify CSS (MatthiasMullie `wppo_exclude_minification`)".

## Organization (not technical buckets Cache/CSS/JS)
- User intent: Speed (Make pages faster) = CSS+JS+HTML+Preload+Server/CDN, Media = Images/Video, Data&System = DB+Redis — balance discoverability vs dev control §16.

## Advanced Categories
- **Advanced Performance:** Combine `B2`, UsedCSS `B4`, Critical `B5`, Delay `B11`, Woo `B12` (when Woo `class-main.php:1166`), Speculation `C6`, Ai `A20`.
- **Caching:** Logged-in `A8` role hash `class-cache.php:297`, CDN `B15` hostname `1684`, Edge `A19` Workers `EdgeCachePanel:261`.
- **Assets:** Exclude lists with handle picker autocomplete from `Asset_Manager:245` metabox `class-metabox.php:453` + Per-page `post meta _wppo_disabled_scripts` `class-metabox.php`.
- **Database:** Orphaned `E6` review, oEmbed `E7`, OPTIMIZE `E8` — advanced behind confirm `DatabaseCleanup:618`.
- **Developer:** Hooks `wppo_*` 30+ `docs/hooks.md:493`, WP-CLI 7 verbs `--dry-run`, Redis Sentinel/Cluster `ObjectCache:488-643`, bfcache/OD/perf_translations `class-main.php:453`.

## Discoverability
- Global search "defer, redis, critical, combine" filters disclosure — not hidden impossible `Agent M`.
- URL `?tab=speed&section=js&advanced=1` deep-link replaces `useState` `App.js:76` state-only.
- "Show advanced" toggle persists `wppoSettings.show_advanced` (new).

## Not Dumb Mode
- L1 still powerful via Recommended one-click 10 safe toggles — less configuration, not less capability §19.
