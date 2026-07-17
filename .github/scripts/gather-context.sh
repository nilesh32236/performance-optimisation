#!/usr/bin/env bash
# gather-context.sh
# Gathers full context from an issue and/or PR for OpenCode prompts.
# Outputs a markdown file with all relevant context.
#
# Usage:
#   gather-context.sh <repo> <output-file> [--issue <N>] [--pr <N>]
#
# Environment:
#   GH_TOKEN — required for GitHub API access

set -euo pipefail

REPO=""
OUTPUT_FILE=""
ISSUE_NUM=""
PR_NUM=""

while [[ $# -gt 0 ]]; do
  case $1 in
    --issue) ISSUE_NUM="$2"; shift 2 ;;
    --pr)    PR_NUM="$2"; shift 2 ;;
    *)
      if [ -z "$REPO" ]; then REPO="$1"
      elif [ -z "$OUTPUT_FILE" ]; then OUTPUT_FILE="$1"
      fi
      shift ;;
  esac
done

[ -z "$REPO" ] && { echo "Error: repo is required"; exit 1; }
[ -z "$OUTPUT_FILE" ] && { echo "Error: output file is required"; exit 1; }

{
  if [ -n "$ISSUE_NUM" ]; then
    ISSUE_DATA=$(gh api "repos/${REPO}/issues/${ISSUE_NUM}" \
      --jq '{title: .title, body: (.body // "No description"), labels: [.labels[].name]}' \
      2>/dev/null || echo '{"title":"Unknown","body":"Failed to fetch","labels":[]}')

    ISSUE_TITLE=$(echo "$ISSUE_DATA" | jq -r '.title')
    ISSUE_BODY=$(echo "$ISSUE_DATA" | jq -r '.body')
    ISSUE_LABELS=$(echo "$ISSUE_DATA" | jq -r '.labels | join(", ")')

    echo "## Issue #${ISSUE_NUM}"
    echo ""
    echo "**Title:** ${ISSUE_TITLE}"
    [ -n "$ISSUE_LABELS" ] && echo "**Labels:** ${ISSUE_LABELS}"
    echo ""
    echo "### Description"
    echo ""
    echo "${ISSUE_BODY}"
    echo ""

    COMMENTS=$(gh api "repos/${REPO}/issues/${ISSUE_NUM}/comments" \
      --jq '.[] | "**@\(.user.login)** (\(.created_at)):\n\(.body)\n"' 2>/dev/null || echo "")
    if [ -n "$COMMENTS" ]; then
      echo "### Issue Comments"
      echo ""
      echo "$COMMENTS"
      echo ""
    fi
  fi

  if [ -n "$PR_NUM" ]; then
    PR_DATA=$(gh api "repos/${REPO}/pulls/${PR_NUM}" \
      --jq '{title: .title, body: (.body // "No description")}' \
      2>/dev/null || echo '{"title":"Unknown","body":"Failed to fetch"}')

    PR_TITLE=$(echo "$PR_DATA" | jq -r '.title')
    PR_BODY=$(echo "$PR_DATA" | jq -r '.body')

    echo "## PR #${PR_NUM}"
    echo ""
    echo "**Title:** ${PR_TITLE}"
    echo ""
    echo "### PR Description"
    echo ""
    echo "${PR_BODY}"
    echo ""

    PR_COMMENTS=$(gh api "repos/${REPO}/issues/${PR_NUM}/comments" \
      --jq '.[] | "**@\(.user.login)** (\(.created_at)):\n\(.body)\n"' 2>/dev/null || echo "")
    if [ -n "$PR_COMMENTS" ]; then
      echo "### PR Comments"
      echo ""
      echo "$PR_COMMENTS"
      echo ""
    fi

    REVIEW_COMMENTS=$(gh api "repos/${REPO}/pulls/${PR_NUM}/comments" \
      --jq '.[] | "**@\(.user.login)** on `\(.path):\(.line // .original_line)`:\n\(.body)\n"' 2>/dev/null || echo "")
    if [ -n "$REVIEW_COMMENTS" ]; then
      echo "### Inline Review Comments"
      echo ""
      echo "$REVIEW_COMMENTS"
      echo ""
    fi

    REVIEWS=$(gh api "repos/${REPO}/pulls/${PR_NUM}/reviews" \
      --jq '.[] | select(.body != "" and .body != null) | "**@\(.user.login)** (\(.state)):\n\(.body)\n"' 2>/dev/null || echo "")
    if [ -n "$REVIEWS" ]; then
      echo "### Reviews"
      echo ""
      echo "$REVIEWS"
      echo ""
    fi
  fi

} > "$OUTPUT_FILE"

LINES=$(wc -l < "$OUTPUT_FILE")
echo "Context gathered: ${LINES} lines -> ${OUTPUT_FILE}"
