# DESIGN-DECISION-MATRIX.md — Agent Votes + Synthesis

**Vote per Agent A-M + N QA** (Recommended why, major concern, must preserve, should change)

| Agent | Recommended | Why | Major concern | Must preserve | Should change |
|-------|-------------|-----|---------------|---------------|---------------|
| A Normal | B | 20 confusing terms `FileOptimization:54-57` delay vs defer, 40 toggles overwhelm `FileOptimization:40-87` | Hiding breaks checkout Woo `1145` | Outcome wording "Make pages faster" | 7 flat tabs → 4 pillars |
| B Developer | B (not C) | Per-page Asset Manager `767`, delay strategies `72-104`, Sentinel/Cluster `ObjectCache:488` needed | C hides Redis/debug `M` | 30+ hooks `docs/hooks.md`, WP-CLI 1093 | File monolith 1970 split CSS/JS |
| C UX | B | IA flat 7 vs grouped 4+1, Dashboard 8 cards ~2800, File 5 sub-tabs no route `App:76` | C SaaS hides discoverability | Roving tablist `251-274` focus trap `215-260` | Health header 3 rings not 4 stats `Dashboard:823` |
| D PM | B | Dashboard health critical auto stats `Agent D`, File very high but 80% auto | A too little vs C too much | Recommended one-click safe 10 | Outcome over implementation §4 |
| E Rationalization | B | 55 rows 28 visible + 12 advanced + 9 diagnostic mapping `Agent E` | A keeps 40 visible load | No REMOVE, tier preserved | AUTOMATE 5 + RECOMMEND 10 per `AUTOMATION-CANDIDATES.md` |
| F IA | B | Merge/split 4+1 outcome-based "Speed/Media/Data&System/Manage" `Agent F` | 7→4 needs URL mapping | `wppo_settings` keys `Util:43` | File split CSS/JS/Server |
| G Onboarding | B | Wizard 6 steps `WelcomePanel:9` 3→6 with Detect→Verify vs 20-step `File:40-87` | Welcome auto-dismiss `78` loses | Wizard entry + progress | Not 20 steps |
| H Performance | B | Raw TTFB/LCP `PerformanceAudit:121` → badge Good/Needs first | C hides numbers entirely | Badge `StatusBadge` thresholds `121-129` | Health 3 pillars vs raw tables `506` |
| I Safety | B | Safe defaults all `false:170` + snapshots `Rest:464` + `?wppo_safe=1` | C auto-apply risks checkout | `ConfirmDialog:618` sample before delete | Feedback not auto |
| J Competitive | B | FlyingPress Recommended vs Advanced `competitive:32-63` + Rocket 3 toggles `:38` + NitroPack one-click `:62` patterns | A too Rocket-like old | "No credits no cloud" moat `competitive:81` | Don't fragment RUM+Telemetry `79` |
| K Accessibility | B | Preserve `ToggleControl` `SwitchField:50` + `:focus-visible` `base:108` + `Tooltip aria-describedby:30` | C hides focus order | Roving + traps | Tooltip span→button |
| L Architecture | B | `wppo_settings` single option `Main:266` `setup_hooks:489` 35 hooks `wppoSettings` global `Main:1565` — B needs no rewrite `28 endpoints tab` generic | C requires backend rewrite `AGENTS.md:18` manual require | Hooks `wppo_*` 30+ | Product layer only UI |
| M Adversarial | B (against C) | Challenges: hiding Sentinel `ObjectCache:922`, defer INP loss, bloat 15 `Main:199-214` vs 50, per-page `Metabox:453`, safe defaults `delayJS off:196`, discoverability via search `M` | B must keep Advanced discoverable via search+URL | Advanced search | Must not dumb mode `§19` |
| N QA | B | Real site LS hit `x-litespeed-cache: hit` but `is_litespeed:201` CLI mismatch, 681 failed queue no UI, 11/14 tabs backfill `Agent N` | C hides diagnostics needed for QA | `build 134K` `cache 2.9M` verified | Fix detection + failed queue error |

**Tally:** A 0, B 14, C 0 — **B unanimous** (M contested but voted B against C).

## Final Synthesis (not majority alone, evidence)
- **Winning:** **Option B — Recommended Product Redesign** — evidence: 7→4 grouping reduces clicks 40→2, health 3 rings replaces 4 stats+8 cards `Dashboard:1329`, Recommended 10 safe covers 80% `Main:170` all-false → ON without hiding dev power (Advanced search `?advanced=1` keeps per-page `767` + Sentinel `488` + hooks `docs/hooks.md:493`).
- **Why not A:** Insufficient simplification — File 1970 monolith + Dashboard ~2800 remain, 40 toggles still visible per `FEATURE-INVENTORY.md` 28 visible; not enough for Persona 1 "Make faster".
- **Why not C:** Too simplistic becomes dumb mode `§19` — hides useful `M` (combine B2, delay B11, MIME D7) making debugging harder; backend rewrite needed `AGENTS.md:18` manual load, high risk `?wppo_safe=1` not enough.
- **Decision:** Ship B in 3 sprints Phases 1-3 then Advanced 4, Onboarding 5, Safety 6, A11y 7, Testing 8 — per `IMPLEMENTATION-PLAN.md`.

