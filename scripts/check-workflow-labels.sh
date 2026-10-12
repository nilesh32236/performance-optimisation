#!/usr/bin/env bash
#
# check-workflow-labels.sh
#
# A check that CAN fail, and that IS run (webpack.yml -> opencode-installer):
# asserts that every GitHub label every workflow in .github/workflows/ references
# actually exists in this repository.
#
# Why this exists: growth-monitor.yml created an issue with
#   gh issue create --label growth-monitor
# and the `growth-monitor` label did not exist, so the step failed with
# "Not Found" the first time a material public-signal change was detected. On a
# schedule that can be weeks after the workflow was written, and it arrives as
# a broken scheduled run rather than a red default-branch gate.
# model-intelligence.yml had the same latent shape with `model-registry`.
#
# Two rules this check follows, both of which a simpler version violated:
#
#   1. It must be WIRED INTO CI. A guard nothing executes cannot fail, and a
#      check that cannot fail is the same defect as the bug it guards.
#   2. Self-created labels are skipped INCLUDING QUOTED ones. A workflow running
#      `gh label create "audit"` makes its own label and demanding it would be a
#      false positive. An earlier version's regex matched none of the ten quoted
#      `gh label create` lines here, so the skip matched nothing while the check
#      still reported PASS -- the worse failure mode, because it looks like it
#      works.
#
# Usage:  scripts/check-workflow-labels.sh
# Exit:   0 = every referenced label exists or is self-created
#         1 = a label is missing, or the label list could not be read (fail closed)
# Needs:  gh, authenticated against this repository.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKFLOW_DIR="$ROOT/.github/workflows"

if [ ! -d "$WORKFLOW_DIR" ]; then
  echo "check-workflow-labels: no .github/workflows directory at $ROOT" >&2
  exit 1
fi
if ! command -v gh >/dev/null 2>&1; then
  echo "check-workflow-labels: gh is not installed; cannot verify. Failing closed." >&2
  exit 1
fi

EXISTING="$(mktemp)"; CREATED="$(mktemp)"
trap 'rm -f "$EXISTING" "$CREATED"' EXIT

REPO="$(gh repo view --json nameWithOwner --jq .nameWithOwner 2>/dev/null || true)"
if [ -z "$REPO" ]; then
  echo "check-workflow-labels: could not resolve the repository. Failing closed." >&2
  exit 1
fi
if ! gh api "repos/$REPO/labels?per_page=100" --jq '.[].name' > "$EXISTING" 2>/dev/null; then
  echo "check-workflow-labels: could not read the label list for $REPO. Failing closed." >&2
  exit 1
fi

# Labels this repo's own workflows create, QUOTED OR NOT. The trim strips quote
# characters; without it the skip set holds \"audit\" while the reference set
# holds audit, and the two never match.
grep -rhoE 'gh label create ["'"'"']?[A-Za-z0-9:._-]+' "$WORKFLOW_DIR" 2>/dev/null \
  | sed -E 's/^gh label create //' \
  | tr -d "\"'" \
  | grep -v '^$' \
  | sort -u > "$CREATED" || true

# Referenced labels. Capture is [^[:space:]&|]+ because a label name never
# contains whitespace: what follows the first space is the next shell argument.
grep -rhoE -- '--label[= ]+[^[:space:]&|]+' "$WORKFLOW_DIR" 2>/dev/null \
  | sed -E 's/^--label[= ]+//' \
  | tr ',' '\n' \
  | tr -d "\"'" \
  | grep -v '^$' \
  | sort -u \
  | while IFS= read -r label; do
      case "$label" in
        *'$'*) continue ;;              # templated; cannot resolve statically
      esac
      grep -Fxq "$label" "$CREATED" 2>/dev/null && continue
      grep -Fxq "$label" "$EXISTING" && continue
      echo "MISSING LABEL: '$label' is referenced by a workflow but does not exist in $REPO."
      echo "                Create it, or add a 'gh label create <name> --force' step."
      exit 1
    done

echo "check-workflow-labels: every referenced label exists (or is created by the workflow)."
