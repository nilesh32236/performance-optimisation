#!/usr/bin/env bash
# Phase E docs gate (issue #1636): manifest / link / source checks.
# Usage: npm run docs:check
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAP="$ROOT/docs/growth/DOCUMENTATION-MAP.md"
SITE="$ROOT/docs/site"
FAIL=0

fail() { echo "docs-check FAIL: $*" >&2; FAIL=1; }
pass() { echo "docs-check OK: $*"; }

# Template headings every feature page must contain (beginner-first order).
REQUIRED_HEADINGS=(
  "## What it does"
  "## When to use it"
  "## Safe default"
  "## How to enable"
  "## What changes"
  "## Compatibility"
  "## Verify"
  "## Undo"
  "## Troubleshooting"
  "## FAQ"
)

# 1. Manifest: every docs/site/*.md file listed in the map must exist.
if [[ ! -f "$MAP" ]]; then
  fail "DOCUMENTATION-MAP.md missing at $MAP"
  exit 1
fi

map_files="$(grep -o 'docs/site/[a-z-]*\.md' "$MAP" | sort -u)"
if [[ -z "$map_files" ]]; then
  fail "no docs/site entries found in DOCUMENTATION-MAP.md"
fi

while IFS= read -r rel; do
  [[ -z "$rel" ]] && continue
  if [[ -f "$ROOT/$rel" ]]; then
    pass "manifest: $rel exists"
  else
    fail "manifest: $rel listed in map but file missing"
  fi
done <<< "$map_files"

# Every docs/site/*.md file must be listed in the map (no orphan thin pages).
for f in "$SITE"/*.md; do
  base="$(basename "$f")"
  if grep -q "docs/site/$base" "$MAP"; then
    pass "manifest: $base listed in map"
  else
    fail "manifest: $base exists but is not listed in DOCUMENTATION-MAP.md"
  fi
done

# Meta-hub pages answer index/navigation questions rather than feature
# questions, so they carry a lighter contract: What-it-does + links +
# sources (+ label reference), instead of the full feature template.
HUB_PAGES="troubleshooting.md faq.md compatibility.md"

is_hub() {
  local base="$1"
  for h in $HUB_PAGES; do
    [[ "$base" == "$h" ]] && return 0
  done
  return 1
}

# 2. Template headings per page.
for f in "$SITE"/*.md; do
  base="$(basename "$f")"
  if is_hub "$base"; then
    for h in "## What it does"; do
      grep -qF "$h" "$f" || fail "template: $base (hub) missing heading '$h'"
    done
    continue
  fi
  for h in "${REQUIRED_HEADINGS[@]}"; do
    if grep -qF "$h" "$f"; then
      : # heading present
    else
      fail "template: $base missing heading '$h'"
    fi
  done
  # Beginner-first order: "What it does" must precede "Technical reference".
  what_line="$(grep -nF '## What it does' "$f" | head -1 | cut -d: -f1 || true)"
  tech_line="$(grep -nF '## Technical reference' "$f" | head -1 | cut -d: -f1 || true)"
  if [[ -n "$what_line" && -n "$tech_line" && "$tech_line" -lt "$what_line" ]]; then
    fail "template: $base puts Technical reference before What it does"
  fi
  # Status labels: Compatibility section must use at least one label.
  if grep -q '`verified`\|`supported`\|`best effort`\|`known limitation`' "$f"; then
    : # labels present
  else
    fail "compat: $base uses no status label (verified/supported/best effort/known limitation)"
  fi
done
pass "template headings + label scan complete"

# 3. Links: every relative .md link must resolve.
for f in "$SITE"/*.md; do
  base="$(basename "$f")"
  # Extract [text](target.md[#anchor]) links, excluding http(s) URLs.
  while IFS= read -r link; do
    [[ -z "$link" ]] && continue
    target="${link%%#*}" # strip anchor
    if [[ -f "$SITE/$target" ]]; then
      : # resolves
    else
      fail "links: $base -> $link does not resolve"
    fi
  done < <(grep -oP '\]\(\K(?!https?://)[^)]+?\.md(?:#[^)]+)?(?=\))' "$f" || true)
done
pass "link resolution complete"

# 4. Sources: every page must cite at least one code/readme source path.
for f in "$SITE"/*.md; do
  base="$(basename "$f")"
  if grep -qE 'includes/[A-Za-z0-9_./-]+\.php|readme\.txt|templates/object-cache\.php' "$f"; then
    : # source cited
  else
    fail "sources: $base cites no code/readme source path"
  fi
done
pass "source citation scan complete"

if [[ "$FAIL" -ne 0 ]]; then
  echo "docs-check: FAILED" >&2
  exit 1
fi
echo "docs-check: PASSED"
