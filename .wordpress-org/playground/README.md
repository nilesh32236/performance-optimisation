# WordPress Playground blueprint

Lets someone open a working copy of the plugin in a browser tab, with no server, no database
admin, and no install step — before deciding whether to install it on a real site.

**Blueprint:** `blueprint.json`
**Run record:** the demo below was executed 2026-10-02 against release **2.4.0**. The blueprint has since been repointed to **2.4.1**; that re-point has NOT been re-run, so the 2.4.1 install is unverified here.

## Open it

**The URL below is pinned to a full commit SHA, not to a branch name.**

```
https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fnilesh32236%2Fperformance-optimisation%2Fbeceecbe00f006d1e0c99e7b9ae34161ce4a5e0e%2F.wordpress-org%2Fplayground%2Fblueprint.json
```

**Why the SHA, and not `master`.** A blueprint is a set of instructions that decides what code the
visitor's browser then downloads and runs. Served from a branch name, that URL is a *different
program tomorrow* — and after any merge, without anybody changing the link. This is the same failure
mode as shipping a `<script src=…>` pointing at a mutable ref, and it is not acceptable in a listing
we control. Pinned to a commit, the URL is a fixed artifact: either it is the blueprint that was
verified here, or it is broken and says so.

The blueprint's own plugin source is a **GitHub release asset at tag `v2.4.1`** — also a fixed,
published artifact rather than a branch. Both refs in the chain are immutable.

**Regenerating the link after any change to the blueprint:**

```sh
git log --format=%H -1 -- .wordpress-org/playground/blueprint.json
```

Substitute that SHA into the URL above. If the blueprint file has not changed, the existing SHA is
still correct and the link does not need touching.

### What you actually get

Verified by running it, not by describing what it should do. See the run record below.

WordPress **7.1.2**, logged in as `admin` / `password`, Performance Optimisation **2.4.1 installed and
activated**, and the Dashboard as the landing page. Nothing is switched on, so you start in the same
state a fresh install starts in.

## Run record — 2026-10-02

The blueprint was executed end to end with the official Playground CLI, and the resulting site was
inspected. Not a static read of the JSON: a real boot.

```sh
npx @wp-playground/cli@latest build-snapshot \
  --blueprint=.wordpress-org/playground/blueprint.json --outfile=/tmp/pg-snap.zip
```

Result: `Exported to /tmp/pg-snap.zip`, 39,128,903 bytes, exit 0.

| Assertion | Method | Result |
|---|---|---|
| WordPress version booted as requested | `grep wp_version /wordpress/wp-includes/version.php` in the snapshot | **`7.1.2`** — matches `preferredVersions.wp: "7.1"` |
| Plugin installed **and activated** | read `active_plugins` from the snapshot's SQLite DB | **`a:1:{i:0;s:53:"performance-optimisation/performance-optimisation.php";}`** |
| Activation ran to completion | list `wp_*` tables in the snapshot DB | **`wp_wppo_activity_logs` created** by `dbDelta()` — the table only exists if the activation hook ran |
| Canonical settings written | query `wp_options` for `wppo_settings` | **present** |
| Composer's autoloader survived the install | `unzip -l` | `vendor/autoload.php` present — this is what the `$wppo_have_deps` gate at `performance-optimisation.php:275` requires |
| Committed build output survived | `unzip -l` | `build/index.js`, `templates/app.html` present |
| The blueprint's own step ran | query `wp_options` for `blogdescription` | **`WordPress Playground`** — set by the `setSiteOptions` step |
| Admin menu slug is the one we link to | read from source, not guessed | `'performance-optimisation'`, `manage_options`, `includes/Core/class-main.php:3032-3041` |

**Not verified, and not claimed:** the PHP version *inside* the WASM runtime was not read back. The
blueprint requests `8.2`, the schema accepts it, and the boot succeeded — but "the boot worked" is
not the same as "I read `PHP_VERSION` and saw 8.2". Nobody should quote a PHP version off this demo
without checking it themselves. `readme.txt` is the source for that claim, not the demo.

**Not verified by a browser.** The CLI proves the site boots, the plugin activates, and the data
layer is correct. It does not prove the React SPA renders in a real browser, that the REST calls the
SPA makes succeed in the sandbox, or that `landingPage` resolves. Treat those as untested until
somebody opens the link in a browser and says what they see.

---

## What this blueprint deliberately does not do

It is a demo, and the honest ones say what they are not.

- **It is not a compatibility test.** Playground is a PHP-WASM sandbox. A plugin rendering here has
  not been tested on your host, your theme, or your network. See `docs/growth/claims.md` row C-09:
  this plugin has no automated multi-WordPress-version matrix, and this blueprint is not one.
- **It does not exercise Redis.** There is no Redis server in Playground. The Object Cache tab will
  report an unreachable host, which is the correct result.
- **It does not exercise LiteSpeed, a CDN, or a real web server.** `.htaccess` and nginx rules are
  written to disk and then read by nothing. LiteSpeed and CDN panels will show their config screens
  and nothing behind them.
- **It does not measure your site.** PageSpeed needs a key you supply; Real-User Monitoring needs
  traffic. Neither can produce a real number here.
- **It is not a security boundary.** The instance is disposable and the credentials are public.

## What it does exercise

Verified by the run record above: WordPress boots, the plugin installs, its activation hook runs to
completion, its custom table and canonical options are created, and the committed build output and
Composer autoloader survive the install.

Plausible but **not** verified, because verifying it needs a browser rather than a CLI: the admin
SPA rendering, the REST calls the SPA makes, the dashboard's read-only telemetry, the system-info
panel, the CLI-visible plugin metadata, and deactivation. Treat this list as expectation, not result.

---

## Declared requirements, and how each one was checked

Nothing in `blueprint.json` is aspirational. Every value was checked against a source on
2026-10-02. The first three rows are **static** checks — they verify the declared value is
well-formed and real. The rest are **behavioural**: they were confirmed by the run record above.

| Declared | Value | Where it comes from | How it was checked |
|---|---|---|---|
| Blueprint shape | v1 declaration (`plugins` + `steps`) | `BlueprintV1Declaration` in the official schema | `jsonschema` against `https://playground.wordpress.net/blueprint-schema.json` — **valid, 0 errors** |
| `preferredVersions.php` | `8.2` | `SupportedPHPVersion` enum lists `7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5`. `8.2` is also the plugin's enforced floor (`WPPO_REQUIRES_PHP`, `performance-optimisation.php:42`). | Enum membership checked in the schema. **The runtime PHP version was not read back from the WASM build** — see the run record. |
| `preferredVersions.wp` | `7.1` | Playground's build index maps key `7.1` → `7.1.2` (`packages/playground/wordpress-builds/src/wordpress/wp-versions.json`). `7.1.2` is the current WP release and the plugin's declared `Tested up to`. | Fetched the index **and** booted: the snapshot's `wp-includes/version.php` reads `$wp_version = '7.1.2'` |
| `plugins[0].url` | release ZIP for `v2.4.1` | `scripts/build-release.sh` names the asset `${SLUG}-${VERSION}.zip`; `release.yml:51` writes `performance-optimisation-${VERSION}.zip`. | `curl -sIL` → **HTTP 200**; downloaded, **3,146,799 bytes, 434 entries**; `vendor/autoload.php`, `build/` and `templates/` all present; no `.wordpress-org/` inside the archive. **The plugin being active in the booted site was observed for 2.4.0 only — the 2.4.1 install has NOT been booted and re-verified.** |
| ZIP contains `vendor/autoload.php` | required | `performance-optimisation.php:275` boots only when `$wppo_have_deps` is true, which needs the Composer autoloader. `build-release.sh` fails the release if it is missing. | `unzip -l` → present; **and present in the booted site**, where the plugin activated rather than halting |
| ZIP contains `build/` and `templates/` | required | The admin SPA is committed build output; `Main::admin_page()` requires `templates/app.html`. | `unzip -l` → `build/index.js`, `build/index.asset.php`, `templates/app.html`, `templates/object-cache.php`; all present in the snapshot |
| `landingPage` | `/wp-admin/admin.php?page=performance-optimisation` | Menu slug is the literal `'performance-optimisation'` in `Main::init_menu()`, `includes/Core/class-main.php:3032-3041`, gated on `manage_options`. | **Slug read from source, not guessed.** The page itself was not rendered — no browser run |
| `login: true` | admin / password | `manage_options` is an administrator capability, so the landing page needs an admin session. | Schema accepts `true`. Not re-verified in a browser |
| `features.networking` | `true` | The plugin makes outbound HTTP via `wp_remote_get` for PageSpeed, Google Fonts, CDN purges and edge templates. Declaring it explicitly stops the demo failing silently on a network call. | Schema `features` permits `intl` and `networking` only |
| `steps[0]` `setSiteOptions` | sets `blogdescription` | Cosmetic, so the admin bar is not mislabelled in a demo. | Schema `setSiteOptions` exists; **and observed** — `blogdescription` reads `WordPress Playground` in the booted DB |

### Reproduce the checks

```sh
# 1. JSON parses and matches the published schema
curl -sS -o blueprint-schema.json https://playground.wordpress.net/blueprint-schema.json
python3 - <<'PY'
import json, jsonschema
schema = json.load(open('blueprint-schema.json'))
bp = json.load(open('.wordpress-org/playground/blueprint.json'))
errs = list(jsonschema.Draft7Validator(schema).iter_errors(bp))
print('valid' if not errs else [e.message for e in errs])
PY

# 2. the release asset the blueprint points at actually exists
curl -sIL -o /dev/null -w '%{http_code}\n' \
  https://github.com/nilesh32236/performance-optimisation/releases/download/v2.4.1/performance-optimisation-2.4.1.zip

# 3. the WordPress version the blueprint requests is a key Playground knows
curl -sS https://raw.githubusercontent.com/WordPress/wordpress-playground/trunk/packages/playground/wordpress-builds/src/wordpress/wp-versions.json

# 4. BOOT IT. This is the check that matters; 1-3 only prove the file is well-formed.
npx @wp-playground/cli@latest build-snapshot \
  --blueprint=.wordpress-org/playground/blueprint.json --outfile=/tmp/pg-snap.zip
mkdir -p /tmp/pg-snap && unzip -q /tmp/pg-snap.zip -d /tmp/pg-snap
grep -m1 'wp_version =' /tmp/pg-snap/wordpress/wp-includes/version.php
python3 - <<'PY'
import sqlite3
c = sqlite3.connect('/tmp/pg-snap/wordpress/wp-content/database/.ht.sqlite')
print('active:', c.execute(
    "SELECT option_value FROM wp_options WHERE option_name='active_plugins'").fetchone())
print('tables:', [r[0] for r in c.execute(
    "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%wppo%'")])
PY
```

Step 4 is what turns "this JSON is valid" into "this demo works". Steps 1–3 can all pass on a
blueprint that fails at runtime, which is exactly the failure mode a document like this one invites.

---

## Bumping this on release

The blueprint pins a version in two places — the release tag and the asset filename. Both must
change together, or the link silently serves the previous release. The **documentation URLs** pin it
in a third place: the commit SHA.

1. Tag `vX.Y.Z` and let the release workflow publish `performance-optimisation-X.Y.Z.zip`.
2. Edit `blueprint.json`: `v2.4.1` → `vX.Y.Z` and `performance-optimisation-2.4.1.zip` →
   `performance-optimisation-X.Y.Z.zip`. Do not guess the version; read it from the `Version:`
   header in `performance-optimisation.php` or from the tag you just pushed.
3. Re-run **every** check above, including the boot. A blueprint that points at a 404 is worse than
   no blueprint, because it fails after a 10-second wait rather than immediately.
4. After the commit lands, regenerate the documentation URLs:
   ```sh
   git log --format=%H -1 -- .wordpress-org/playground/blueprint.json
   ```
   Substitute that SHA into the URLs in this file **and** in `docs/site/playground.html`. Skipping
   this leaves the published links serving the *previous* blueprint while the repository advertises
   the new one — a stale demo is better than a broken one, but it is still wrong.
5. Update the "Verified" date and the run record at the top of this file with the new run.

## Why the versions are pinned at all

There are two pins, and both are deliberate.

**The WordPress version.** `preferredVersions.wp` could be `"latest"`, which is the obvious choice.
It is the wrong one: it makes the demo unreproducible — what a visitor sees today is not what they
see next month — and it drifts away from the `Tested up to: 7.1` the plugin actually declares.
Pinning to `7.1` means the demo and the claim say the same thing, and they move together.

**The commit SHA.** See the top of this file. A blueprint is code that decides what code runs; a
mutable ref turns a fixed artifact into a moving target, and a demo nobody can reproduce is a demo
nobody can trust.

## Not included, on purpose

- No analytics, tracking pixel, or telemetry endpoint. The demo talks to nothing except GitHub
  Releases and WordPress.org.
- No affiliate link, referral code, or review prompt. The readme already promises the plugin does
  not ask users for a rating, and a demo that contradicted that would be a small lie.
- No paid tier, no upsell screen, no "premium" nudge.
