# WordPress Playground blueprint

Lets someone open a working copy of the plugin in a browser tab, with no server, no database
admin, and no install step — before deciding whether to install it on a real site.

**Blueprint:** `blueprint.json`
**Verified:** 2026-10-02 against the plugin release 2.4.0

## Open it

```
https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fnilesh32236%2Fperformance-optimisation%2Fmaster%2F.wordpress-org%2Fplayground%2Fblueprint.json
```

That URL is long. Once this file is on `master`, the short form works too:

```
https://playground.wordpress.net/#https://raw.githubusercontent.com/nilesh32236/performance-optimisation/master/.wordpress-org/playground/blueprint.json
```

What you get: WordPress 7.1 on PHP 8.2, logged in as `admin` / `password`, the plugin installed and
activated, and the Performance Optimisation Dashboard already open. Nothing is switched on, so you
land in the same state a real fresh install lands in.

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

The admin SPA, the REST surface, settings persistence, the dashboard's read-only telemetry, the
system-info panel, the CLI-visible plugin metadata, activation and deactivation, and the
filesystem drop-ins (`advanced-cache.php`, `wppo_settings`, the activity-log table).

---

## Declared requirements, and how each one was checked

Nothing in `blueprint.json` is aspirational. Every value was checked against a source on 2026-10-02.

| Declared | Value | Where it comes from | Check run |
|---|---|---|---|
| Blueprint shape | v1 declaration (`plugins` + `steps`) | `BlueprintV1Declaration` in the official schema | `jsonschema` validation against `https://playground.wordpress.net/blueprint-schema.json` — **valid, 0 errors** |
| `preferredVersions.php` | `8.2` | `SupportedPHPVersion` enum in the schema lists `7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5`. `8.2` is the plugin's own enforced floor (`WPPO_REQUIRES_PHP`, `performance-optimisation.php:42`). | Enum membership checked in the schema |
| `preferredVersions.wp` | `7.1` | Playground's build index maps the key `7.1` to `7.1.2` — `packages/playground/wordpress-builds/src/wordpress/wp-versions.json` in `WordPress/wordpress-playground`. `7.1.2` is the current WordPress release, and it is the plugin's declared `Tested up to`. | Fetched that file and read the key |
| `plugins[0].url` | release ZIP for `v2.4.0` | `scripts/build-release.sh` names the asset `${PLUGIN_SLUG}-${VERSION}.zip`, and `.github/workflows/release.yml:51` writes `performance-optimisation-${VERSION}.zip`. | `curl -sIL` returns **HTTP 200**; archive downloaded, 1,910,682 bytes |
| ZIP contains `vendor/autoload.php` | required | `performance-optimisation.php:275` boots only when `$wppo_have_deps` is true, which depends on the Composer autoloader. `build-release.sh` fails the release if it is missing. | `unzip -l` — present |
| ZIP contains `build/` and `templates/` | required | The admin SPA is committed build output; `Main::admin_page()` requires `templates/app.html`. | `unzip -l` — `build/index.js`, `build/index.asset.php`, `templates/app.html`, `templates/object-cache.php` all present |
| `landingPage` | `/wp-admin/admin.php?page=performance-optimisation` | The menu slug is the literal `'performance-optimisation'` in `Main::init_menu()`, `includes/Core/class-main.php:3032-3041`, gated on `manage_options`. | Read from source |
| `login: true` | admin / password | `manage_options` is an administrator capability, so a logged-in admin is required for the landing page to render. | Schema `login` accepts `true` |
| `features.networking` | `true` | The plugin makes outbound HTTP through `wp_remote_get` for PageSpeed, Google Fonts, CDN purges, and edge-cache templates. Declaring it explicitly means the demo does not silently fail on a network call. | Schema `features` accepts `intl` and `networking` only |
| `steps[0]` `setSiteOptions` | sets `blogdescription` | Cosmetic, so the admin bar is not mislabelled in a demo. | Schema `setSiteOptions` step exists |

### Reproduce the checks

```sh
# JSON parses and matches the published schema
curl -sS -o blueprint-schema.json https://playground.wordpress.net/blueprint-schema.json
python3 - <<'PY'
import json, jsonschema
schema = json.load(open('blueprint-schema.json'))
bp = json.load(open('.wordpress-org/playground/blueprint.json'))
errs = list(jsonschema.Draft7Validator(schema).iter_errors(bp))
print('valid' if not errs else [e.message for e in errs])
PY

# the release asset the blueprint points at actually exists
curl -sIL -o /dev/null -w '%{http_code}\n' \
  https://github.com/nilesh32236/performance-optimisation/releases/download/v2.4.0/performance-optimisation-2.4.0.zip

# the WordPress version the blueprint requests is a key Playground knows
curl -sS https://raw.githubusercontent.com/WordPress/wordpress-playground/trunk/packages/playground/wordpress-builds/src/wordpress/wp-versions.json

# the ZIP really carries the files the plugin needs at boot
unzip -l performance-optimisation-2.4.0.zip | grep -E 'vendor/autoload.php|build/index.js|templates/app.html'
```

---

## Bumping this on release

The blueprint pins a version in two places — the release tag and the asset filename. Both must
change together, or the link silently serves the previous release.

1. Tag `vX.Y.Z` and let the release workflow publish `performance-optimisation-X.Y.Z.zip`.
2. Edit `blueprint.json`: `v2.4.0` → `vX.Y.Z` and `performance-optimisation-2.4.0.zip` →
   `performance-optimisation-X.Y.Z.zip`. Do not guess the version; read it from the `Version:`
   header in `performance-optimisation.php` or from the tag you just pushed.
3. Re-run every check in the section above. A blueprint that points at a 404 is worse than no
   blueprint, because it fails after a 10-second wait rather than immediately.
4. Update the "Verified" date at the top of this file.

## Why the version is pinned at all

`preferredVersions.wp` could be `"latest"`, and it is the more obvious choice. It is the wrong one
here. `"latest"` makes the demo unreproducible: what a visitor sees today is not what they see next
month, and it drifts away from the `Tested up to: 7.1` this plugin actually declares. Pinning to
`7.1` means the demo and the claim say the same thing. If the plugin's `Tested up to` changes, this
file changes with it, in the same release, deliberately.

## Not included, on purpose

- No analytics, tracking pixel, or telemetry endpoint. The demo talks to nothing except GitHub
  Releases and WordPress.org.
- No affiliate link, referral code, or review prompt. The readme already promises the plugin does
  not ask users for a rating, and a demo that contradicted that would be a small lie.
- No paid tier, no upsell screen, no "premium" nudge.
