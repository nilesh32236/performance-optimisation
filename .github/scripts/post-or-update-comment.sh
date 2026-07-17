#!/usr/bin/env bash
# post-or-update-comment.sh
# Posts a new comment or updates an existing one on a PR/issue.
# Uses an HTML marker to find existing comments.
#
# Usage:
#   post-or-update-comment.sh <repo> <issue-number> <marker> <body-file> <GH_TOKEN>

set -euo pipefail

REPO="$1"
ISSUE_NUM="$2"
MARKER="$3"
BODY_FILE="$4"
export GH_TOKEN="$5"

[ -f "$BODY_FILE" ] || { echo "Body file not found: $BODY_FILE" >&2; exit 1; }

BODY_CONTENT=$(cat "$BODY_FILE")
MARKED_BODY="${MARKER}
${BODY_CONTENT}"

EXISTING_ID=$(gh api "repos/${REPO}/issues/${ISSUE_NUM}/comments" \
  --jq --arg marker "$MARKER" '.[] | select(.body | startswith($marker)) | .id' | head -1)

if [ -n "$EXISTING_ID" ]; then
  printf '%s' "$MARKED_BODY" | jq -Rs '{body: .}' | \
    gh api "repos/${REPO}/issues/comments/${EXISTING_ID}" -X PATCH --input - --silent
  echo "Comment updated (id: ${EXISTING_ID})" >&2
else
  printf '%s' "$MARKED_BODY" | jq -Rs '{body: .}' | \
    gh api "repos/${REPO}/issues/${ISSUE_NUM}/comments" --input - --silent
  echo "Comment posted" >&2
fi
