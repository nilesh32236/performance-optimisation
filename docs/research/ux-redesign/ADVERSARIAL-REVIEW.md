# ADVERSARIAL-REVIEW.md — What Could Go Wrong If We Implement B?

**Challenged by Agent M + synthesis 14 agents** per §40.

| Simplification | Could go wrong? | Evidence | Mitigation |
|----------------|-----------------|----------|------------|
| Hiding Combine UsedCSS Delay | Dev can't find FOUC fix `FileOptimization:399-409` or GTM delay `72-104` | `M` hiding Sentinel `ObjectCache:922`/`delay JS breaks checkout` `1124` | Advanced search `?tab=speed&advanced=1` + URL deep-link + "Show advanced (9)" persistent `ADVANCED-MODE.md` |
| Recommended defaults 10 ON | Are defaults safe? `delayJS off:196` was safe off, ON breaks Woo `1145` cart | `Main:170-214` all-false was safe because off | Recommended excludes delay/combine/usedCSS/critical `B2/B4/B5/B11` advanced only; snapshot + Undo `SAFETY-RECOVERY.md` |
| Automatic cache enable | Could surprise user on membership site `A8` `wppo_role_hash` :297 leak | `Agent N` 11/14 tabs missing bfcache etc backfill | AUTOMATE only when no membership plugin detected `is_plugin_active` + logged-in OFF by default `Agent D` |
| Hiding Redis Sentinel | Enterprise can't configure Cluster `ObjectCache:580` TLS `669` | 90% Standalone `Agent B` but 10% need | Standalone collapsed primary, Enterprise behind "Show enterprise" disclose `F1` SIMPLIFY not hide |
| Health 3 rings vs raw numbers | Removing raw TTFB 200/500 `PerformanceAudit:121` hides perf specialist need `Persona 4` | `H` raw table `506-810` 16 rows needed | Rings badge first, numbers in tooltip/drawer `?healthDetails=1` `DASHBOARD-DESIGN.md` — not removed |
| Warnings simplified | Users ignore `FileOptimization:400` yellow fatigue already | `Agent A` warning fatigue | Benefit/risk/badge per toggle + confirm `ConfirmDialog:618` sample, not yellow everywhere |
| Configuration migration 7→4 | Existing bookmarks `?tab=fileOptimization` break `App.js:76` state-only | `MIGRATION-PLAN.md` URL map `App.js` redirect | `useEffect` old→new mapping `dashboard→overview` etc + no DB `wppo_settings` key change `Util:43` |
| Accessibility hidden toggles | `Tooltip.js:30` span not button, `SwitchField:41` no `aria-describedby` lost when collapsed | `K` gaps | Keep roving `251-274` + `:focus-visible` `base:108` + fix span→button in B `ACCESSIBILITY.md` |
| Plugin conflicts WP Rocket | Auto minify double `SystemInfo:633` not detected vs LS only `Dashboard:686` | `CONFLICT-DETECTION.md` missing detection | Add `is_plugin_active` check banner "Another plugin handles minify — leave off" `I` |
| Debugging harder | "Simple" becomes impossible to find per-page Asset Manager `class-metabox.php:453` not in SPA | `M` debugging | Keep metabox link in Speed→Assets header + WP-CLI `class-wppo-cli-command.php` + hooks `docs/hooks.md` |
| Auto preload sitemap 500 cap 15s | `Cron::get_sitemap_urls:500 cap 15s` hidden, user expects all URLs warmed | `C2` 500 cap hidden | Advanced tooltip "500 cap, index→child" `PreloadSettings.js` |
| Performance of health header | 3 rings calculation on every Dashboard mount `Dashboard.js:1329` + audit `841` | `L` 35 hooks `setup_hooks:489` | Header computed from cached transients `wppo_cache_size 15m` `wppo_total_js_css` no extra query |

**Revised design accordingly:** Keep Advanced discoverable, Recommended excludes high-risk `B2/B4/B11`, add snapshots + kill-switch `?wppo_safe=1` `SAFETY-RECOVERY.md`, search + deep-link, raw numbers drawer, conflict banner expanded.

**Verdict:** B safe with above mitigations — no evidence to block.

