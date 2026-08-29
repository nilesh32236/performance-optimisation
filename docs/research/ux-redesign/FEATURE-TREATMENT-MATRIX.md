# FEATURE-TREATMENT-MATRIX.md — KEEP/SIMPLIFY/GROUP/AUTOMATE/RECOMMEND/ADVANCED/DIAGNOSTICS

**55 rows** from `FEATURE-INVENTORY.md` — Treatment per §33, no REMOVE unless obsolete (none).

| # | Feature | Treatment | New location | Reason |
|---|---------|-----------|--------------|--------|
| A1 | Welcome checklist | SIMPLIFY | Overview→Wizard step1 | Auto-dismiss loses steps `WelcomePanel.js:78` → wizard with progress |
| A2 | LS banner | CONTEXTUAL | Overview banner when LS detected `Dashboard.js:686` | Only when LS, not always |
| A3 | Cache Size + Purge | KEEP | Overview header | Daily use, health |
| A4 | Optimized Files | SIMPLIFY | Health Efficiency pillar | Count → % savings badge |
| A5 | DB Overhead | KEEP | Health Stability pillar | Badge good |
| A6 | Images Optimized | GROUP | Media pillar | Merge with Image tab |
| A7 | Enable Cache + Lifespan | RECOMMEND | Overview Quick Setup | Default OFF `Main:170` → ON recommended |
| A8 | Logged-in Cache | MOVE TO ADVANCED | Speed→Advanced | Role hash jargon `class-cache.php:297` |
| A9 | CDN purge | CONTEXTUAL | Speed→Network when CDN | Token via constant `:1119` invisible |
| A10 | Performance Audit | KEEP | Diagnostics | Manual but needed |
| A11 | Suggestions | GROUP | Health actions list `Dashboard.js:78` | Merge telemetry+PSI |
| A12 | PageSpeed PSI | KEEP | Health → Run scan | Weekly |
| A13 | Trends | GROUP | Health history | Auto cron |
| A14 | RUM | RECOMMEND | Health field data | Cost note needed |
| A15 | Autoload | MOVE TO DIAGNOSTICS | Diagnostics | Dev term |
| A16 | System Info | DIAGNOSTIC | Diagnostics on demand | Support |
| A17 | ImageCard | GROUP | Media | Dupe |
| A18 | Recent Activity | KEEP | Manage→Activity | Teams |
| A19 | Edge Cache | ADVANCED | Advanced→Edge | OFF `AiPanel.js:291` until enabled |
| A20 | AI Adaptive | ADVANCED | Advanced→AI | OFF applies settings |
| A21 | LLMs.txt | ADVANCED | Advanced→LLMs | OFF |
| B1 | Minify CSS | RECOMMEND | Speed→CSS | Safe |
| B2 | Combine CSS | ADVANCED | Speed→CSS advanced | High FOUC `FileOptimization.js:399` |
| B3 | Remove Query Strings | ADVANCED | Speed→CSS advanced | Rare |
| B4 | Remove Unused CSS | ADVANCED | Speed→CSS advanced | High missing styles |
| B5 | Critical CSS | RECOMMEND | Speed→CSS | LCP gain |
| B6 | Host Google Fonts | KEEP | Speed→CSS | GDPR |
| B7 | Minify HTML + Remove Comments | KEEP | Speed→HTML | Everyone |
| B8 | Minify Inline CSS/JS | GROUP | Under B7 | Sub-toggle `FileOptimization.js:84` |
| B9 | Minify JS | RECOMMEND | Speed→JS | Safe |
| B10 | Defer JS | RECOMMEND | Speed→JS | Safe |
| B11 | Delay JS 5 fields | ADVANCED | Speed→JS advanced | High checkout `936-1123` |
| B12 | Woo Assets | CONTEXTUAL | Speed→JS when Woo | Only Woo `1145` |
| B13 | LiteSpeed Owner | CONTEXTUAL | Speed→Server when LS | Only LS |
| B14 | Server Rules .htaccess | ADVANCED | Advanced→Server | High lockout `1521` |
| B15 | CDN Hostname | CONTEXTUAL | Speed→Network when CDN | Manual |
| B16 | Core Tweaks 11 | GROUP | Advanced→WordPress | Collapse under Tweaks search |
| B17 | Block Assets On Demand | AUTOMATE | Hidden WP6.9+ `Main:185` | Auto on 6.9 |
| B18 | Load All Block + Heartbeat | ADVANCED | Advanced→WordPress | Rare |
| C1 | Cache Warm-up | KEEP | Speed→Preload | Daily |
| C2 | Preload Sitemap | ADVANCED | Speed→Preload advanced | 500 cap |
| C3 | Preconnect | ADVANCED | Speed→Connections advanced | 3P |
| C4 | DNS Prefetch | ADVANCED | Speed→Connections advanced | Many 3P |
| C5 | Preload Fonts/CSS | KEEP | Speed→Preload | Once |
| C6 | Speculation | ADVANCED | Speed→Preload advanced | Med inflate `433` |
| D1 | Lazy Native | AUTOMATE | Media→Lazy | ON `ImageOptimization.js:42` |
| D2 | Exclude First N | KEEP | Media→Lazy | Slider |
| D3 | Bg lazy + Placeholder | ADVANCED | Media→Lazy advanced | Thumbs |
| D4 | Wrap Picture | GROUP | Under D6 | Required |
| D5 | Video Lazy | KEEP | Media→Video | YouTube |
| D6 | Convert WebP/AVIF | RECOMMEND | Media→Next-Gen | 25-50% |
| D7 | MIME override HEIC/JXL | ADVANCED | Media→Next-Gen advanced | WP7.1 |
| D8 | Responsive Max Width | ADVANCED | Media→Limits advanced | Mobile |
| D9 | LCP Preload PSI | ADVANCED | Media→Preload advanced | Requires PSI |
| E1 | Revisions 30/5 | KEEP | Data→Cleanup | Weekly |
| E2 | Auto Drafts | KEEP | Data→Cleanup | Weekly |
| E3 | Trashed Posts | KEEP | Data→Cleanup | Weekly |
| E4 | Spam/Trashed Comments | KEEP | Data→Cleanup | Weekly |
| E5 | Expired Transients | KEEP | Data→Cleanup | Daily |
| E6 | Orphaned Meta + Unattached | ADVANCED | Data→Cleanup advanced | High false `54-127` |
| E7 | oEmbed | ADVANCED | Data→Cleanup advanced | Hidden |
| E8 | Schedule OPTIMIZE | KEEP | Data→Cleanup | Once |
| E9 | Total overhead hero | KEEP | Data→Cleanup | Safety sample needed |
| F1 | Redis Standalone | SIMPLIFY | Data→Object Cache collapsed | DB 0-15 jargon `:37` |
| F2 | Sentinel | ADVANCED | Data→Object Cache enterprise | 90% off |
| F3 | Cluster | ADVANCED | Data→Object Cache enterprise | Off |
| F4 | TLS/persistent/compression Flush | KEEP | Data→Object Cache | Once |
| F5 | Status PONG | DIAGNOSTIC | Diagnostics | Dev |
| G1 | Activity Log | KEEP | Manage→Activity | Teams |
| G2 | PageSpeed Key | KEEP | Manage→API Keys | Once |
| G3 | System Info refresh | DIAGNOSTIC | Diagnostics | Dev |
| G4 | Export/Import REDACTED | KEEP | Manage→Tools | Agencies |
| G5 | Monitoring toggles | GROUP | Manage→Monitoring | 3 flat → group |

No REMOVE — all preserved, just tiered.
