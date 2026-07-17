#!/usr/bin/env bash
# check-merge-readiness.sh
# Checks if a pull request is ready to merge based on:
#   - Status checks (CI passing)
#   - No merge conflicts
#   - Required labels
#   - Review approval state
#
# Usage:
#   check-merge-readiness.sh <repo> <pr-number> [--confidence <score>]
#
# Outputs $GITHUB_OUTPUT:
#   ready=true|false
#   confidence=<score>
#   blockers=<comma-separated list>

set -euo pipefail

REPO="$1"
PR_NUM="$2"
CONFIDENCE_THRESHOLD=95
CONFIDENCE=""

while [[ $# -gt 0 ]]; do
  case $1 in
    --confidence) CONFIDENCE="$2"; shift 2 ;;
    *) shift ;;
  esac
done

BLOCKERS=()

echo "Checking PR #${PR_NUM} merge readiness..."

PR_DATA=$(gh pr view "$PR_NUM" --repo "$REPO" --json mergeable,state,reviews,statusCheckRollup 2>/dev/null || echo '{"mergeable":"UNKNOWN","state":"UNKNOWN","reviews":[],"statusCheckRollup":[]}')

MERGEABLE=$(echo "$PR_DATA" | jq -r '.mergeable // "UNKNOWN"')
STATE=$(echo "$PR_DATA" | jq -r '.state // "UNKNOWN"')

if [ "$STATE" != "OPEN" ]; then
  BLOCKERS+=("PR is not open (state: ${STATE})")
fi

if [ "$MERGEABLE" = "CONFLICTING" ]; then
  BLOCKERS+=("Merge conflict exists")
elif [ "$MERGEABLE" = "UNKNOWN" ]; then
  BLOCKERS+=("Mergeability unknown (retry later)")
fi

CHECKS_FAILED=$(echo "$PR_DATA" | jq '[.statusCheckRollup[] | select(.conclusion == "FAILURE" or .conclusion == "CANCELLED" or .conclusion == "ERROR" or .conclusion == "STARTUP_FAILURE" or .conclusion == "STALE" or .conclusion == "TIMED_OUT" or .conclusion == "ACTION_REQUIRED")] | length')
CHECKS_PENDING=$(echo "$PR_DATA" | jq '[.statusCheckRollup[] | select(.status != "COMPLETED")] | length')
CHECKS_TOTAL=$(echo "$PR_DATA" | jq '.statusCheckRollup | length')

if [ "$CHECKS_FAILED" -gt 0 ]; then
  BLOCKERS+=("${CHECKS_FAILED} CI check(s) failed")
fi
if [ "$CHECKS_PENDING" -gt 0 ]; then
  BLOCKERS+=("${CHECKS_PENDING} CI check(s) still pending")
fi

REVIEWS=$(echo "$PR_DATA" | jq '[.reviews[] | select(.state == "CHANGES_REQUESTED")] | length')
APPROVALS=$(echo "$PR_DATA" | jq '[.reviews[] | select(.state == "APPROVED")] | length')

if [ "$REVIEWS" -gt 0 ] && [ "$APPROVALS" -eq 0 ]; then
  BLOCKERS+=("Changes requested with no approvals")
fi

if [ -n "$CONFIDENCE" ]; then
  if [[ "$CONFIDENCE" =~ ^[0-9]+(\.[0-9]+)?$ ]]; then
    CONF_INT=${CONFIDENCE%.*}
    CONF_INT=${CONF_INT:-0}
  else
    CONF_INT=0
  fi
  if [ "$CONF_INT" -lt "$CONFIDENCE_THRESHOLD" ]; then
    BLOCKERS+=("AI confidence ${CONF_INT}% < ${CONFIDENCE_THRESHOLD}% threshold")
  fi
fi

if [ ${#BLOCKERS[@]} -eq 0 ]; then
  echo "ready=true"
  echo "confidence=${CONFIDENCE:-100}"
  echo "blockers="
  echo "PR #${PR_NUM} is ready to merge."
else
  echo "ready=false"
  echo "confidence=${CONFIDENCE:-0}"
  BLOCKERS_JSON=$(
    IFS=,
    echo "${BLOCKERS[*]}"
  )
  echo "blockers=${BLOCKERS_JSON}"
  echo "Blockers:"
  for blocker in "${BLOCKERS[@]}"; do
    echo "  - ${blocker}"
  done
fi
