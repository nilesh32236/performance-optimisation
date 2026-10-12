#!/usr/bin/env bash
#
# check-workflow-labels.sh
#
# A check that can fail: asserts that every GitHub label every workflow in
# .github/workflows/ references actually exists in this repository.
#
# Why this exists: growth-monitor.yml created an issue with
#   gh issue create --label growth-monitor
# and the `growth-monitor` label did not exist, so the step failed with
# "Not Found" the first time a material public-signal change was detected. On a
# schedule that can be weeks after the workflow was written, and it arrives as
# a broken scheduled run rather than a red default-branch gate.
# model-intelligence.yml had the same latent shape with `model-registry`.
#
# A workflow that creates its own label with `gh label create --force` is
# self-healing and cannot fail this way; upstream-monitor.yml in the reviewer
# repo does exactly that. Those lines are detected and their labels skipped, so
# the check never demands a label the workflow would have made anyway.
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

# Labels this repo's own workflows create, so they need not pre-exist.
grep -rhoE 'gh label create [A-Za-z0-9:._-]+' "$WORKFLOW_DIR" 2>/dev/null \
  | awk '{print $4}' > "$CREATED" || true

# Referenced labels. The capture is [^[:space:]&|]+ because a label name never
# contains whitespace: anything after the first space is the next argument of
# the shell command and must not be swallowed into the label name.
grep -rhoE -- '--label[= ]+[^[:space:]&|]+' "$WORKFLOW_DIR" 2>/dev/null \
  | sed -E 's/^--label[= ]+//' \
  | tr ',' '\n' \
  | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//" \
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
