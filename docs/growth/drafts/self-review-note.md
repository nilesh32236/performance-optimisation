# The one review on the listing is the author's own

**Recorded:** 2026-10-02. **Listing version reviewed:** 2.4.0, uploaded 2026-09-24.
**Status: note only. Nothing in this file was done, and nothing here should be done by anyone who
does not own the account.**

---

## What is on the listing

The listing shows **4 ratings at 100%**. The API returns `rating: 100`, `num_ratings: 4`,
`active_installs: 0`.

Of those four reviews, three are from outside accounts:

| Reviewer | Date | Substance |
|---|---|---|
| `maulik32` | 2025-08-29 | Specific: names the plugins it replaced and the features used. |
| `manan7035` | 2025-08-28 | Generic. "Extremely useful, works great." No detail. |
| `Vu Tru So (vutruso)` | 2025-08-23 | Asks a real question about preload caching. The most useful of the four. |
| **Nilesh Kanzariya (`nilesh912`)** | **2025-02-25** | **The plugin author. Reviews their own plugin.** |

The fourth is the plugin author's own WordPress.org account reviewing their own plugin. It is
counted in the 100%.

### Verify it yourself

```sh
curl -sS "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=performance-optimisation" \
  | python3 -c "import json,sys,re,html; \
      s=json.load(sys.stdin)['sections']['reviews']; \
      print(re.findall(r'href=\"[^\"]*profiles[^\"]*\"[^>]*>([^<]*)<', s))"
```

`nilesh912` is the same slug that appears in `Contributors: nilesh912` in `readme.txt` and as the
author profile returned by the API.

---

## Why this matters more than a tidiness issue

Four ratings is a tiny sample. One of four is 25% of the score. Remove the author's own review and
the listing shows **3 ratings** — which is a more accurate picture, and also a lower one. That is
fine. A 100% average built on one of the four being a self-review is not a fact about the plugin; it
is an artefact of the accounting.

The directory guidelines are clear that reviews are for people who use the plugin, and a maintainer
reviewing their own plugin is not that. The specific harm is not the flattering number. It is that
the one visible "review" on the listing is the least informative thing on the page — it describes
features the plugin has, rather than an experience of using it — so it teaches a prospective user
nothing while appearing to vouch for the listing.

The three genuine reviews are good. `maulik32` and `vutruso` are the kind of review that helps
somebody decide. The listing would be stronger with those three and nothing else.

---

## Options — for the account owner only

**Option 1 — Remove it.** WordPress.org lets a reviewer delete their own review. `nilesh912` edits
or deletes the review posted 2025-02-25. The listing drops to 3 ratings and the average recalculates.

**Option 2 — Convert it into a "Details" block instead of a review.** Move the useful part — the
feature description — into the readme where it belongs, and remove the review. Same outcome as
option 1 plus the content survives in a place where it is honest.

**Option 3 — Leave it, and disclose it.** Least good. It is defensible only if the listing says
plainly, near the rating, that one of the four reviews is from the author. A disclosure nobody sees
under a star graphic is not a disclosure.

**Recommendation: option 2.** The review text is basically a feature summary, and the readme
already covers those features better. Nothing of value is lost; the accounting becomes true.

## What NOT to do

These are the failure modes this note exists to prevent, so they are written down:

- **Do not ask anyone for a review.** Not in the readme, not in the admin UI, not on the docs site,
  not in a support reply, not in the Playground demo. No incentivised review, no "rate us if you
  like it", no reciprocal arrangement, no review exchange with another plugin author. The current
  readme already states the rule plainly — *"The plugin does not ask for a rating or a review to use
  its features"* — and that sentence is worth more to this listing than any fourth review.
- **Do not create another account to review it.** A second account by the same person is the same
  problem with more steps, and it is discoverable.
- **Do not seed reviews from anyone with a relationship to the author**, paid or unpaid.
- **Do not reply to the existing reviews asking for edits or upgrades.** Leave the three genuine
  reviews alone. They are not ours to manage.
- **Do not gate any feature, support answer, or documentation behind a review.** No.
- **Do not let an agent touch this.** Anything that involves the author account — deleting a review,
  editing a profile, changing a listing setting — is a human decision. This file is the handoff.

## Scope note

The plugin code does not contain a rating prompt, a review request, a review gate, or any outreach
mechanism. `readme.txt` states the opposite policy in writing, and the Playground blueprint added
alongside this note explicitly excludes rating prompts. The only self-review is the one on the
listing itself, which is outside the repository.

Fixing it is a five-minute job for the person who owns `nilesh912`, and it is that person's call.
